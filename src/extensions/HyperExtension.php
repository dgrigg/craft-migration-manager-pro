<?php

namespace dgrigg\migrationassistant\extensions;

use Craft;
use yii\base\Event;
use dgrigg\migrationassistant\events\ExportEvent;
use dgrigg\migrationassistant\events\ImportEvent;
use dgrigg\migrationassistant\services\BaseMigration;
use dgrigg\migrationassistant\services\BaseContentMigration;
use dgrigg\migrationassistant\helpers\ElementHelper;
use verbb\hyper\fields\Hyper;
use verbb\hyper\base\ElementLink;
use craft\helpers\ArrayHelper;

/**
 * Class LinkFieldHelper
 */
class HyperExtension
{
 
    function __construct(){

        Event::on(BaseMigration::class, BaseMigration::EVENT_BEFORE_EXPORT_FIELD_VALUE, function (ExportEvent $event) {
            $element = $event->element;
            if ($element->className() == 'verbb\hyper\fields\HyperField') {
                $service = $event->service;
                $values = $event->value;
                $event->value = [];               

                foreach ($values as $key => $link) {
                    $linkFields = $link->getFieldLayout()->getCustomFields();
                    $linkValue = $link->getInputConfig();
                    
                    if ($link->hasElement()){
                        $element = [ElementHelper::getSourceHandle($link->getElement())];
                        $linkValue['element'] = $element;                           
                    }

                    $fields = [];
                    foreach ($linkFields as $field) {
                        $service->getFieldContent($fields, $field, $link);
                    }
                    $linkValue['fields'] = $fields;
                    $event->value[$key] = $linkValue;                            
                }
              
            }
        });

        Event::on(BaseContentMigration::class, BaseMigration::EVENT_BEFORE_IMPORT_FIELD_VALUE, function (ImportEvent $event) {
            $element = $event->element;
            
            if ($element->className() == 'verbb\hyper\fields\HyperField') {
                $values = $event->value;
                
                $service = $event->service;

                foreach($values as $key => $value) {
                    if (key_exists('element', $value)){
                        $ids =  ElementHelper::populateIds($value['element']);
                        $linkValue['linkValue'] = $ids[0];
                        unset($linkValue['element']);
                    }

                    if (key_exists('fields', $value)){
                        $service->validateImportValues($value['fields']);
                    }

                    $values[$key] = $value;
                }
                $event->value = $values;           
            }
        });
    }
    
}