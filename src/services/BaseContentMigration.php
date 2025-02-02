<?php

namespace dgrigg\migrationassistant\services;

use dgrigg\migrationassistant\helpers\ElementHelper;
use dgrigg\migrationassistant\events\ExportEvent;
use dgrigg\migrationassistant\events\ImportEvent;
use Craft;
use craft\fields\BaseOptionsField;
use craft\fields\BaseRelationField;
use craft\helpers\MoneyHelper;

abstract class BaseContentMigration extends BaseMigration
{
    /**
     * @param $content
     * @param $element
     */
    public function getContent(&$content, $element)
    {
        foreach ($element->getFieldLayout()->getCustomFields() as $fieldModel) {
            $this->getFieldContent($content['fields'], $fieldModel, $element);
        }
    }

    /** 
     * @param $content
     * @param $fieldModel
     * @param $parent
     */

    public function getFieldContent(&$content, $fieldModel, $parent)
    {
        $field = $fieldModel;
        $value = $parent->getFieldValue($field->handle); 

        switch ($field->className()) {
            case 'craft\redactor\Field':
                if ($value) {
                    $value = $value->getRawContent();
                } else {
                    $value = '';
                }

                break;
            case 'craft\fields\Matrix':
                $model = $parent[$field->handle];
                $model->limit = null;
                $value = $this->getIteratorValues($model, function ($item) {
                    $itemType = $item->getType();
                    $value = [
                        'type' => $itemType->handle,
                        'enabled' => $item->enabled,
                        'sortOrder' => $item->sortOrder,
                        'title' => $item->title,
                        'slug' => $item->slug,
                        'collapsed' => $item->collapsed,
                        'fields' => []                        
                    ];

                    return $value;
                });
                break;
            case 'benf\neo\Field':
                $model = $parent[$field->handle];
                $value = $this->getIteratorValues($model, function ($item) {
                    $itemType = $item->getType();
                    $value = [
                        'type' => $itemType->handle,
                        'enabled' => $item->enabled,
                        'modified' => $item->enabled,
                        'collapsed' => $item->collapsed,
                        'level' => $item->level,
                        'fields' => []
                    ];

                    return $value;
                });
                break;
            case 'verbb\supertable\fields\SuperTableField':
                $model = $parent[$field->handle];
                $value = $this->getIteratorValues($model, function ($item) {
                    $value = [
                        'type' => $item->typeId,
                        'fields' => []
                    ];
                    return $value;
                });

                break;
            case 'craft\fields\Dropdown':
                $value = $value->value;
                break;
            case 'craft\fields\Color':
                $value = (string)$value;
                break;
            case 'craft\fields\Money':
                $value = [
                    'value' => $value->getAmount(),
                    'currency' => $value->getCurrency()
                ];       
                break;
            case 'craft\fields\Addresses':
                $addresses = $field->serializeValue($value, $parent );
                $value = array_values($addresses);
                break;
            default:
                if ($field instanceof BaseRelationField) {
                    ElementHelper::getSourceHandles($value, $this);
                } elseif ($field instanceof BaseOptionsField) {
                    $this->getSelectedOptions($value);
                }
                break;
        }

        //export the field value
        $value = $this->onBeforeExportFieldValue($field, $value);

        if (is_array($value) == false && is_object($value) == false){
            $value = [
                'value' => $value
            ];
        }

        if (is_object($value)) {
            $value = (array) $value;
        }

        $value['context'] = $field->context; 
        
        //set the field context              
        $content[$field->handle] = $value;
    }

    /**
     * Fires an 'onBeforeImport' event.
     *
     * @param Event $event
     *          $event->params['element'] - model to be imported, manipulate this to change the model before it is saved
     *          $event->params['value'] - data used to create the element model
     *
     * @return null
     */
    public function onBeforeExportFieldValue($element, $data)
    {
        $event = new ExportEvent(array(
            'element' => $element,
            'value' => $data,
            'service' => $this
        ));
        $this->trigger($this::EVENT_BEFORE_EXPORT_FIELD_VALUE, $event);
        return $event->value;
    }

    /**
     * @param $values
     */
    public function validateImportValues(&$values, $ownerId = false)
    {
        foreach ($values as $key => &$value) {
            $this->validateFieldValue($values, $key, $value, $ownerId);
        }
    }

    /**
     * Set field values for supported custom fields
     *
     * @param $parent - parent element
     * @param $fieldHandle - field handle
     * @param $fieldValue - value in field
     */

    protected function validateFieldValue($parent, $fieldHandle, &$fieldValue, $ownerId)
    {
        $field = Craft::$app->fields->getFieldByHandle($fieldHandle, $fieldValue['context']);

        if ($field) {
            //remove the context value
            unset($fieldValue['context']);

            if ($field instanceof BaseRelationField) {
                if (is_array($fieldValue)){
                    ElementHelper::populateIds($fieldValue);
                } else {
                    $fieldValues = [$fieldValue];
                    ElementHelper::populateIds($fieldValues);
                }
            } else {
                switch ($field::class) {
                    case 'craft\fields\Matrix':
                        foreach ($fieldValue as $key => &$matrixBlock) {
                            $blockType =  Craft::$app->entries->getEntryTypeByHandle($matrixBlock['type']);
                            if ($blockType) {
                                $blockFields = Craft::$app->fields->getAllFields('matrixBlockType:' . $blockType->id);
                                foreach ($blockFields as &$blockField) {
                                    if ($blockField->className() == 'verbb\supertable\fields\SuperTableField') {
                                        $matrixBlockFieldValue = $matrixBlock['fields'][$blockField->handle];
                                        $this->updateSupertableFieldValue($matrixBlockFieldValue, $blockField);
                                    }
                                }
                                $this->validateImportValues($matrixBlock['fields'], $ownerId);
                            }
                        }
                        break;

                    case 'benf\neo\Field':
                        foreach ($fieldValue as $key => &$neoBlock) {
                            $blockType = ElementHelper::getNeoBlockType($neoBlock['type'], $field->id);
                            if ($blockType) {
                                $blockFields = $blockType->getFieldLayout()->getCustomFields();
                                foreach ($blockFields as &$blockTabField) {
                                    $neoBlockField = Craft::$app->fields->getFieldById($blockTabField->fieldId ?? $blockTabField->id);
                                    if ($neoBlockField->className() == 'verbb\supertable\fields\SuperTableField') {
                                        $neoBlockFieldValue = $neoBlock['fields'][$neoBlockField->handle];
                                        $this->updateSupertableFieldValue($neoBlockFieldValue, $neoBlockField);
                                    }
                                }
                                $this->validateImportValues($neoBlock['fields'], $ownerId);
                            }
                        }
                        break;

                    case 'verbb\supertable\fields\SuperTableField':
                        $this->updateSupertableFieldValue($fieldValue, $field);
                        break;
                }
            }

            //pull back the value            
            if (is_array($fieldValue) && key_exists('value', $fieldValue)){
                $fieldValue = $fieldValue['value'];
            }

            $value = $this->onBeforeImportFieldValue($field, $fieldValue, $ownerId);
            $fieldValue = $value;
        } 
    }

    /**
     * Fires an 'onBeforeImport' event.
     *
     * @param Event $event
     *          $event->params['element'] - model to be imported, manipulate this to change the model before it is saved
     *          $event->params['value'] - data used to create the element model
     *
     * @return null
     */
    public function onBeforeImportFieldValue($element, $data, $ownerId = false)
    {
        $event = new ImportEvent(array(
            'element' => $element,
            'value' => $data,
            'ownerId' => $ownerId,
            'service' => $this
        ));
        $this->trigger($this::EVENT_BEFORE_IMPORT_FIELD_VALUE, $event);
        return $event->value;
    }

    /**
     * @param $fieldValue
     * @param $field
     */
    protected function updateSupertableFieldValue(&$fieldValue, $field)
    {
        $plugin = Craft::$app->plugins->getPlugin('super-table');
        $blockTypes = $plugin->service->getBlockTypesByFieldId($field->id);
        if ($blockTypes) {
            $blockType = $blockTypes[0];
            foreach ($fieldValue as $key => &$value) {
                $value['type'] = $blockType->id;
                $this->validateImportValues($value['fields']);
            }
        }
    }

    /**
     * @param $element
     * @param $settingsFunc
     * @return array
     */
    public function getIteratorValues($element, $settingsFunc)
    {
        $items = $element->all();
        $value = [];
        $i = 1;

        foreach ($items as $item) {
            $itemType = $item->getType();
            $itemFields = $itemType->getFieldLayout()->getCustomFields();
            $itemValue = $settingsFunc($item);
            $fields = [];

            foreach ($itemFields as $field) {
                $this->getFieldContent($fields, $field, $item);
            }

            $itemValue['fields'] = $fields;
            $value['new' . $i] = $itemValue;
            $i++;
        }
        return $value;
    }

    /**
     * @param $handle
     * @param $sectionId
     * @return bool
     */
    protected function getEntryType($handle, $sectionId)
    {
        $entryTypes = Craft::$app->entries->getEntryTypesBySectionId($sectionId);
        foreach ($entryTypes as $entryType) {
            if ($entryType->handle == $handle) {
                return $entryType;
            }
        }

        return false;
    }

    /**
     * @param $value
     * @return array
     */
    protected function getSelectedOptions(&$value)
    {
        $options = $value->getOptions();
        $value = [];
        foreach ($options as $option) {
            if ($option->selected) {
                $value[] = $option->value;
            }
        }
        return $value;
    }

}
