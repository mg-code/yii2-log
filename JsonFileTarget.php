<?php

namespace mgcode\log;

use yii\helpers\ArrayHelper;
use yii\helpers\Json;
use yii\log\FileTarget;
use yii\log\Logger;

/**
 * Log target that saves logs to json file.
 * This log target is inspired by this package: https://github.com/index0h/yii2-log
 * Some of functions were used and rewritten.
 */
class JsonFileTarget extends FileTarget
{
    /**
     * @inheritdoc
     */
    public function collect($messages, $final)
    {
        $this->messages = array_merge(
            $this->messages,
            static::filterMessages($messages, $this->getLevels(), $this->categories, $this->except)
        );
        $count = count($this->messages);

        if ($count > 0 && ($final || $this->exportInterval > 0 && $count >= $this->exportInterval)) {
            $this->addContextToMessages();

            // set exportInterval to 0 to avoid triggering export again while exporting
            $oldExportInterval = $this->exportInterval;
            $this->exportInterval = 0;
            $this->export();
            $this->exportInterval = $oldExportInterval;

            $this->messages = [];
        }
    }

    /**
     * Formats a log message.
     * @param array $message The log message to be formatted.
     * @return string
     */
    public function formatMessage($message)
    {
        $message = $this->sanitizeMessage($message);
        return Json::encode($this->prepareMessage($message));
    }

    /**
     * Generates the context information to be logged.
     * @return array
     */
    protected function getContextMessage()
    {
        $context = [
            'application' => \Yii::$app->id,
        ];
        if (($user = \Yii::$app->get('user', false)) !== null) {
            /** @var \yii\web\User $user */
            $context['userId'] = $user->getId();
        }
        $context['context'] = ArrayHelper::filter($GLOBALS, $this->logVars);
        return $context;
    }

    /**
     * Updates all messages if there are context variables.
     */
    protected function addContextToMessages()
    {
        $context = $this->getContextMessage();
        foreach ($this->messages as &$message) {
            $message[0] = ArrayHelper::merge($this->parseText($message[0]), $context);
        }
    }

    /**
     * Convert's any type of log message to array.
     * @param mixed $text Input log message.
     * @return array
     */
    protected function parseText($text)
    {
        if ($text instanceof \Throwable || $text instanceof \Exception) {
            return ['message' => (string) $text];
        }
        $type = gettype($text);
        switch ($type) {
            case 'array':
                return $text;
            case 'string':
            case 'integer':
                return ['message' => $text];
            case 'object':
                return get_object_vars($text);
            default:
                return ['message' => \Yii::t('log', "Warning, wrong log message type '{$type}'")];
        }
    }

    /**
     * Transform log message to assoc.
     * @param array $message The log message.
     * @return array
     */
    protected function prepareMessage($message)
    {
        list($text, $level, $category, $timestamp) = $message;

        $level = Logger::getLevelName($level);
        $timestamp = date('c', $timestamp);

        $result = ArrayHelper::merge(
            $this->parseText($text),
            ['level' => $level, 'category' => $category, '@timestamp' => $timestamp]
        );
        if (isset($message[4]) === true) {
            $result['trace'] = $message[4];
        }

        return $result;
    }

    /**
     * Sanitize message parameters.
     * Fixes bug with invalid utf-8 characters.
     * @param array $message
     * @return array
     */
    protected function sanitizeMessage($message)
    {
        array_walk_recursive($message, function (&$value) {
            if (is_string($value)) {
                $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
            }
        });
        return $message;
    }
}