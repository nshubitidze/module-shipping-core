<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Model\ResourceModel;

use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Shubo\ShippingCore\Api\Data\DeadLetterEntryInterface;

/**
 * Resource model for {@see \Shubo\ShippingCore\Model\Data\DeadLetterEntry}.
 *
 * Serializes `payload_json` at save-time and decodes after load so callers
 * never see the raw JSON string.
 */
class DeadLetterEntry extends AbstractDb
{
    /** @var list<string> */
    private const JSON_FIELDS = [
        DeadLetterEntryInterface::FIELD_PAYLOAD_JSON,
    ];

    protected function _construct()
    {
        $this->_init(DeadLetterEntryInterface::TABLE, DeadLetterEntryInterface::FIELD_DLQ_ID);
    }

    /**
     * @param AbstractModel $object
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function _beforeSave(AbstractModel $object)
    {
        foreach (self::JSON_FIELDS as $field) {
            $value = $object->getData($field);
            if (is_array($value)) {
                $object->setData($field, (string)json_encode(
                    $value,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                ));
            }
        }
        return parent::_beforeSave($object);
    }

    /**
     * @param AbstractModel $object
     * @return $this
     */
    protected function _afterLoad(AbstractModel $object)
    {
        foreach (self::JSON_FIELDS as $field) {
            $raw = $object->getData($field);
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $object->setData($field, $decoded);
                }
            }
        }
        return parent::_afterLoad($object);
    }

    /**
     * @param AbstractModel $object
     * @return $this
     */
    protected function _afterSave(AbstractModel $object)
    {
        foreach (self::JSON_FIELDS as $field) {
            $raw = $object->getData($field);
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $object->setData($field, $decoded);
                }
            }
        }
        return parent::_afterSave($object);
    }
}
