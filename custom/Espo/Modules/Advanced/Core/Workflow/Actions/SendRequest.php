<?php
/***********************************************************************************
 * The contents of this file are subject to the Extension License Agreement
 * ("Agreement") which can be viewed at
 * https://www.espocrm.com/extension-license-agreement/.
 * By copying, installing downloading, or using this file, You have unconditionally
 * agreed to the terms and conditions of the Agreement, and You may not use this
 * file except in compliance with the Agreement. Under the terms of the Agreement,
 * You shall not license, sublicense, sell, resell, rent, lease, lend, distribute,
 * redistribute, market, publish, commercialize, or otherwise transfer rights or
 * usage to the software or any modified version or derivative work of the software
 * created by or for you.
 *
 * Copyright (C) 2015-2024 Letrium Ltd.
 *
 * License ID: 02847865974db42443189e5f30908f60
 ************************************************************************************/

namespace Espo\Modules\Advanced\Core\Workflow\Actions;

use Espo\Core\ORM\Entity as CoreEntity;
use Espo\ORM\Entity;
use Espo\Core\Exceptions\Error;
use Espo\Modules\Advanced\Core\Workflow\Exceptions\SendRequestError;
use stdClass;

use const CURLE_OPERATION_TIMEDOUT;
use const CURLE_OPERATION_TIMEOUTED;
use const CURLINFO_HEADER_SIZE;
use const CURLINFO_HTTP_CODE;
use const CURLOPT_CONNECTTIMEOUT;
use const CURLOPT_CUSTOMREQUEST;
use const CURLOPT_HEADER;
use const CURLOPT_HTTPHEADER;
use const CURLOPT_POSTFIELDS;
use const CURLOPT_RETURNTRANSFER;
use const CURLOPT_TIMEOUT;
use const CURLOPT_URL;
use const JSON_UNESCAPED_UNICODE;

class SendRequest extends Base
{
    protected function run(Entity $entity, stdClass $actionData): bool
    {
        $requestType = $actionData->requestType ?? null;
        $contentType = $actionData->contentType ?? null;
        $requestUrl = $actionData->requestUrl ?? null;
        $content = $actionData->content ?? null;
        $contentVariable = $actionData->contentVariable ?? null;
        $additionalHeaders = $actionData->headers ?? [];

        if (!$requestUrl) {
            throw new Error("Empty request URL.");
        }

        if (!$requestType) {
            throw new Error("Empty request type.");
        }

        if (!in_array($requestType, ['POST', 'PUT', 'PATCH', 'DELETE', 'GET'])) {
            throw new Error("Not supported request type.");
        }

        $isGet = $requestType === 'GET';

        $requestUrl = $this->applyVariables($requestUrl);

        $contentTypeList = [
            null,
            'application/json',
            'application/x-www-form-urlencoded',
        ];

        if (!in_array($contentType, $contentTypeList)) {
            throw new Error("Unsupported content-type.");
        }

        $post = $this->getPayload($contentType, $content, $contentVariable);

        $timeout = $this->getConfig()->get('workflowSendRequestTimeout', 7);

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $requestUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $requestType);
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS | CURLPROTO_HTTP);

        if (!$isGet) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        }

        $headers = [];

        if ($contentType) {
            $headers[] = 'Content-Type: ' . $contentType;
        }

        foreach ($additionalHeaders as $header) {
            $headers[] = $this->applyVariables($header);
        }

        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $GLOBALS['log']->debug("Workflow: Send request: payload:" . $post);

        $response = curl_exec($ch);

        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_errno($ch);

        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);

        //$header = mb_substr($response, 0, $headerSize);
        $body = mb_substr($response, $headerSize);

        curl_close($ch);

        if ($code && $code >= 400 && $code <= 500) {
            throw new SendRequestError(
                "Workflow: Send Request action: $requestType $requestUrl; Error $code response.",
                $code
            );
        }

        if ($error) {
            if (in_array($error, [CURLE_OPERATION_TIMEDOUT, CURLE_OPERATION_TIMEOUTED])) {
                throw new Error("Workflow: Send Request action: $requestUrl; Timeout.");
            }
        }

        if (!($code >= 200 && $code < 300)) {
            throw new SendRequestError("Workflow: Send Request action: $code response.", $code);
        }

        $this->setResponseVariables($body, $code);

        return true;
    }

    /**
     * @throws Error
     */
    private function getPayload(?string $contentType, ?string $content, ?string $contentVariable): ?string
    {
        $isJson = $contentType === 'application/json';

        if (!$contentVariable) {
            if (!$content) {
                return null;
            }

            $content = $this->applyVariables($content, true);

            if ($isJson) {
                return $content;
            }

            $post = json_decode($content, true);

            foreach ($post as $k => $v) {
                if (is_array($v)) {
                    $post[$k] = '"' . implode(', ', $v) . '"';
                }
            }

            return http_build_query($post);
        }

        if ($contentVariable[0] === '$') {
            $contentVariable = substr($contentVariable, 1);

            if (!$contentVariable) {
                throw new Error("Empty variable.");
            }
        }

        $content = $this->getVariables()->$contentVariable ?? null;

        if (is_string($content)) {
            return $content;
        }

        if (!$content) {
            return null;
        }

        if (!$isJson) {
            if ($content instanceof stdClass) {
                return http_build_query($content);
            }

            throw new Error("Workflow: Send Request action: Bad value in payload variable. Should be string or object.");
        }

        if (
            is_array($content) ||
            $content instanceof stdClass ||
            is_scalar($content)
        ) {
            return json_encode($content);
        }

        throw new Error("Workflow: Send Request action: Bad value in payload variable.");
    }

    private function setResponseVariables($body, $code)
    {
        if (!$this->hasVariables()) {
            return;
        }

        $this->updateVariables(
            (object) [
                '_lastHttpResponseBody' => $body,
                '_lastHttpResponseCode' => $code,
            ]
        );

        //$this->variables->_lastHttpResponseBody = $body;
    }

    private function applyVariables(string $content, bool $isJson = false) : string
    {
        $target = $this->getEntity();

        foreach ($target->getAttributeList() as $a) {
            $value = $target->get($a) ?? '';

            if (
                $isJson &&
                $target->getAttributeParam($a, 'isLinkMultipleIdList') &&
                $target->get($a) === null
            ) {
                $relation = $target->getAttributeParam($a, 'relation');

                if ($relation && $target instanceof CoreEntity && $target->hasLinkMultipleField($relation)) {
                    $value = $target->getLinkMultipleIdList($relation);
                }
            }

            if (!$isJson && is_array($value)) {
                $arr = [];

                foreach ($value as $item) {
                    if (is_string($item)) {
                        $arr[] = str_replace(',', '_COMMA_', $item);
                    }
                }

                $value = implode(',', $arr);
            }

            if (is_string($value)) {
                $value = $isJson ?
                    $this->escapeStringForJson($value) :
                    str_replace(["\r\n", "\r", "\n"], "\\n", $value);
            }
            else if (!is_string($value) && is_numeric($value)) {
                $value = strval($value);
            }
            else if (is_array($value)) {
                $value = json_encode($value);
            }

            if (is_string($value)) {
                $content = str_replace('{$' . $a . '}', $value, $content);
            }
        }

        $variables = $this->getVariables() ?? (object) [];

        foreach (get_object_vars($variables) as $key => $value) {
            if (
                !is_string($value) &&
                !is_int($value) &&
                !is_float($value) &&
                !is_array($value)
            ) {
                continue;
            }

            if (is_int($value) || is_float($value)) {
                $value = strval($value);
            }
            else if (is_array($value)) {
                if (!$isJson) {
                    continue;
                }

                $value = json_encode($value);
            }
            else if (is_string($value)) {
                $value = $isJson ?
                    $this->escapeStringForJson($value) :
                    str_replace(["\r\n", "\r", "\n"], "\\n", $value);
            }
            else {
                continue;
            }

            $content = str_replace('{$$' . $key . '}', $value, $content);
        }

        return $content;
    }

    private function escapeStringForJson(string $string): string
    {
        return substr(json_encode($string, JSON_UNESCAPED_UNICODE), 1, -1);
    }
}
