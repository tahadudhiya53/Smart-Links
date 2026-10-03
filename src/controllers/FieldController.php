<?php

namespace Tahadudhiya\SmartLinks\controllers;

use Craft;
use craft\web\Controller;
use Tahadudhiya\SmartLinks\enums\ValidationErrorCode as Code;
use Tahadudhiya\SmartLinks\fields\LinkEditor;
use Tahadudhiya\SmartLinks\fields\LinkForm;
use Tahadudhiya\SmartLinks\fields\SmartLinkField;
use Tahadudhiya\SmartLinks\models\LinkCollection;
use Tahadudhiya\SmartLinks\models\LinkValue;
use Tahadudhiya\SmartLinks\models\ValidationError;
use Tahadudhiya\SmartLinks\SmartLinks;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * Copying, pasting and duplicating links in the Smart Links field's editor.
 *
 * Links are copied as link data, not as form inputs, so pasting is judged by the link rules and
 * by the field it is pasted into, never by what happens to match. A paste either adds every link
 * exactly as copied, or nothing: a link of a type or preset the field does not offer is refused,
 * never turned into another.
 *
 * Nothing here stores anything. Craft's own control panel access is required, as for any control
 * panel request; what ends up in content is decided, and validated again, when the element is
 * saved through Craft.
 */
class FieldController extends Controller
{
    /** The clipboard format: links as stored, without the UIDs of the occurrences copied. */
    public const CLIPBOARD_VERSION = 1;

    /** Clipboard text larger than this is not link data. */
    private const MAX_CLIPBOARD_BYTES = 65536;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        return true;
    }

    /**
     * Turns links as the editor posts them into clipboard data.
     *
     * Body params: `context` (the editor's signed context), `destination` (which editor the page
     * says it is) and `links` (the links' inputs, as the editor names them under `[links]`).
     */
    public function actionCopy(): Response
    {
        // Only a page with a link editor on it copies links.
        $this->editor();
        $links = $this->request->getBodyParam('links');

        if (!is_array($links) || $links === []) {
            return $this->refuse([new ValidationError('', Code::MISSING, 'There is nothing to copy.')]);
        }

        $plugin = SmartLinks::getInstance();
        [$input, $errors] = LinkForm::fromPost(['links' => $links], $plugin->getLinkTypes()->getTypeSet());
        $result = $plugin->getLinks()->getNormalizer()->normalize($input);
        $errors = array_merge($errors, $result->errors);

        if ($errors !== [] || !$result->value instanceof LinkCollection) {
            return $this->refuse($errors);
        }

        // Copied as stored, so the clipboard holds exactly what the link is; without UIDs,
        // because a paste is a new occurrence.
        $stored = $plugin->getLinks()->getSerializer()->serialize($result->value) ?? ['links' => []];
        $clipboard = [
            'version' => self::CLIPBOARD_VERSION,
            'links' => array_map(static function(array $link): array {
                unset($link['uid']);

                return $link;
            }, $stored['links']),
        ];

        return $this->asJson(['clipboard' => json_encode($clipboard, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'count' => count($clipboard['links'])]);
    }

    /**
     * Renders clipboard links for the editor, or refuses all of them.
     *
     * Body params: `context`, `destination`, `clipboard` (the clipboard text), `count` (how many links the
     * editor holds now) and `operation` (the number the editor reserved for this paste when it
     * started it: links are rendered as `new<operation>-1`, `new<operation>-2`…, keys no other
     * link in the editor can have, however its operations overlap).
     */
    public function actionPaste(): Response
    {
        $editor = $this->editor();
        $clipboard = $this->request->getBodyParam('clipboard');
        $count = $this->request->getBodyParam('count');
        $operation = $this->request->getBodyParam('operation');

        $links = $this->readClipboard($clipboard, $editor);

        if (!$links instanceof LinkCollection) {
            return $this->refuse($links);
        }

        if (!is_string($operation) || !preg_match('/^[1-9]\d{0,8}$/', $operation)) {
            throw new BadRequestHttpException('The number the editor reserved for this paste is required.');
        }

        $keys = array_map(static fn(int $index): string => "new$operation-" . ($index + 1), array_keys($links->links));

        if (!is_string($count) || !preg_match('/^\d{1,9}$/', $count)) {
            throw new BadRequestHttpException('How many links the editor holds is required.');
        }

        if ($editor->max !== null && (int)$count + count($links) > $editor->max) {
            return $this->refuse([new ValidationError('', Code::INVALID, 'This field holds at most {max, number} {max, plural, =1{link} other{links}}.', ['max' => $editor->max])]);
        }

        return $this->asJson(['links' => $editor->newLinksHtml($links, $keys)]);
    }

    /**
     * Clipboard text, read as untrusted input: link data in the clipboard format, every link
     * valid by the link rules, and of a type and preset the editor offers.
     *
     * @return LinkCollection|list<ValidationError>
     */
    private function readClipboard(mixed $clipboard, LinkEditor $editor): LinkCollection|array
    {
        if (!is_string($clipboard) || $clipboard === '' || strlen($clipboard) > self::MAX_CLIPBOARD_BYTES) {
            return [new ValidationError('', Code::INVALID, 'The clipboard does not hold copied links.')];
        }

        $data = LinkForm::decode($clipboard);

        if ($data === null || array_is_list($data) || array_diff(array_keys($data), ['version', 'links']) !== [] || count($data) !== 2) {
            return [new ValidationError('', Code::INVALID, 'The clipboard does not hold copied links.')];
        }

        if ($data['version'] !== self::CLIPBOARD_VERSION) {
            return [new ValidationError('version', Code::UNSUPPORTED_VERSION, 'Links copied in this format cannot be pasted.')];
        }

        if (!is_array($data['links']) || !array_is_list($data['links']) || $data['links'] === []) {
            return [new ValidationError('links', Code::INVALID, 'The clipboard does not hold copied links.')];
        }

        $plugin = SmartLinks::getInstance();
        $normalizer = $plugin->getLinks()->getNormalizer();
        $presets = $plugin->getPresets()->getAllPresets();
        $errors = [];
        $links = [];

        // Each link is read and checked on its own, so every reason a paste is refused is given
        // at once; then all of them are added, or none.
        foreach ($data['links'] as $index => $link) {
            $path = "links[$index]";

            // A copied link is a new occurrence wherever it is pasted, so it never carries one's UID.
            if (is_array($link) && array_key_exists('uid', $link)) {
                $errors[] = new ValidationError("$path.uid", Code::UNKNOWN_KEY, 'Copied links have no UID.');
            }

            $result = $normalizer->normalizeLink($link, $path);
            array_push($errors, ...$result->errors);

            if ($result->isValid() && !$result->value instanceof LinkValue) {
                $errors[] = new ValidationError($path, Code::MISSING, 'This link is empty.');
            }

            if (!$result->value instanceof LinkValue) {
                continue;
            }

            $links[] = $result->value;
            array_push($errors, ...SmartLinkField::ruleProblems($result->value, $path, $editor->types, $editor->presets, $presets));
        }

        if ($errors !== []) {
            return $errors;
        }

        return new LinkCollection($links);
    }

    /**
     * The editor the request is for, with its field's rules as they are now, once its context
     * is known to be signed here, for this editor, and for something the user may edit.
     */
    private function editor(): LinkEditor
    {
        return LinkEditor::fromRequest($this->request->getBodyParam('context'), $this->request->getBodyParam('destination'), Craft::$app->getUser()->getIdentity());
    }

    /**
     * @param list<ValidationError> $errors
     */
    private function refuse(array $errors): Response
    {
        $messages = array_map(static function(ValidationError $error): string {
            if (preg_match('/^links\[(\d+)\]/', $error->path, $match)) {
                return Craft::t('smart-links', 'Link {number}: {message}', ['number' => (int)$match[1] + 1, 'message' => $error->getMessage()]);
            }

            return $error->getMessage();
        }, $errors);

        return $this->asFailure($messages[0] ?? null, [
            'errors' => $messages,
            'codes' => array_map(static fn(ValidationError $error): array => ['path' => $error->path, 'code' => $error->code->value], $errors),
        ]) ?? throw new BadRequestHttpException('The request must accept JSON.');
    }
}
