<?php
namespace SolutionsOutsourced\Fields;

use SilverStripe\Core\ClassInfo;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\CheckboxSetField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldButtonRow;
use SilverStripe\Forms\GridField\GridFieldConfig;
use SilverStripe\Forms\GridField\GridFieldDeleteAction;
use SilverStripe\Forms\GridField\GridFieldToolbarHeader;
use SilverStripe\Forms\HiddenField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\OptionsetField;
use SilverStripe\Forms\Tab;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\Map;
use SilverStripe\UserForms\Model\EditableFormField;
use SilverStripe\View\ArrayData;
use SolutionsOutsourced\Models\EditableCustomOption;
use Symbiote\GridFieldExtensions\GridFieldAddNewInlineButton;
use Symbiote\GridFieldExtensions\GridFieldEditableColumns;
use Symbiote\GridFieldExtensions\GridFieldTitleHeader;


/**
 * Creates an editable field that allows users to choose a list
 * From Campaign Monitor and choose default fields
 * On submission of the form a new subscription will be created
 *
 *
 * @package campaign-monitor-userform
 */
class EditableCampaignMonitorField extends EditableFormField
{


    private static $table_name = 'EditableCampaignMonitorField';

    /**
     * @var string
     */
    private static $singular_name = 'Campaign Monitor Signup Field';

    /**
     * @var string
     */
    private static $plural_name = 'Campaign Monitor Signup Fields';

    /**
     * Set default field type, enabled override via Config
     *
     * @var array
     * @config
     */
    private static $defaultFieldType = CheckboxField::class;

    /**
     * @var array Fields on the user defined form page.
     */
    private static $db = array(
        'FieldType' => 'Varchar(255)',
        'ListID' => 'Varchar(255)',
        'EmailField' => 'Varchar(255)',
        'FirstNameField' => 'Varchar(255)',
        'LastNameField' => 'Varchar(255)',
        'ConsentField' => 'Varchar(255)',
    );

    /**
     * @var array
     */
    private static $has_many = array(
        "CustomOptions" => EditableCustomOption::class
    );


    /**
     * @return FieldList
     */
    public function getCMSFields()
    {
        $fields = parent::getCMSFields();

        // get current user form fields
        $currentFromFields = $this->Parent()->Fields()->map('Name', 'Title')->toArray();

        // check for any lists
        $fieldsStatus = true;
        if ($this->getLists()->Count() > 0) {
            $fieldsStatus = false;
        }

        $fields->addFieldsToTab("Root.Main", array(
            LiteralField::create("CampaignMonitorStart", "<h4>Campaign Monitor Configuration</h4>")->setAttribute("disabled", $fieldsStatus),
            DropdownField::create("ListID", 'Subscripers List', $this->getLists()->map("ListID", "Name"))
                ->setEmptyString("Choose a Campaign Monitor List")
                ->setAttribute("disabled", $fieldsStatus),
            DropdownField ::create("EmailField", 'Email Field', $currentFromFields)->setAttribute("disabled", $fieldsStatus),
            DropdownField::create("FirstNameField", 'First Name Field', $currentFromFields)->setAttribute("disabled", $fieldsStatus),
            DropdownField::create("LastNameField", 'Last Name Field', $currentFromFields)->setAttribute("disabled", $fieldsStatus),
            DropdownField::create("ConsentField", 'Tracking Consent Field', $currentFromFields)->setAttribute("disabled", $fieldsStatus),
            LiteralField::create("CampaignMonitorEnd", "<h4>Other Configuration</h4>"),
            DropdownField::create("FieldType", 'Field Type', array(
                HiddenField::class => "HiddenField",
                DropdownField::class => "DropdownField",
                OptionsetField::class => "OptionsetField",
                CheckboxSetField::class => "CheckboxSetField",
                TextField::class => "TextField",
                CheckboxField::class => "CheckboxField"
            ))
                ->setEmptyString("Select a field type")
                ->setDescription("Default type is CheckboxField"),
        ), 'Type');

        $editableColumns = new GridFieldEditableColumns();
        $editableColumns->setDisplayFields(array(
            'Title' => array(
                'title' => 'Title',
                'callback' => function ($record, $column, $grid) {
                    return TextField::create($column);
                }
            ),
            'Default' => array(
                'title' => _t('EditableMultipleOptionField.DEFAULT', 'Selected by default?'),
                'callback' => function ($record, $column, $grid) {
                    return CheckboxField::create($column);
                }
            )
        ));

        $optionsConfig = GridFieldConfig::create()
            ->addComponents(
                new GridFieldToolbarHeader(),
                new GridFieldTitleHeader(),
                $editableColumns,
                new GridFieldButtonRow(),
                new GridFieldAddNewInlineButton(),
                new GridFieldDeleteAction()
            );
        $optionsGrid = GridField::create(
            'CustomOptions',
            'CustomOptions',
            $this->CustomOptions(),
            $optionsConfig
        );
        $fields->insertAfter('Main', new Tab('CustomOptions'));
        $fields->addFieldToTab('Root.CustomOptions', $optionsGrid);


        return $fields;
    }


    /**
     * @return NumericField
     */
    public function getFormField()
    {
        // get default field type from config or from users selection
        $fieldType = $this->config()->defaultFieldType;
        // check if it's different to the default
        if(!empty($this->FieldType) && $this->FieldType != $fieldType) {
            $fieldType = $this->FieldType;
        }

        // ensure format and data is correct based on type
        if (in_array($fieldType, [DropdownField::class, CheckboxSetField::class, OptionsetField::class])) {
            $field = $fieldType::create($this->Name, $this->Title, $this->getOptionsMap());
        } else {
            $field = $fieldType::create($this->Name, $this->Title);
        }
        // set defaults
        $defaultOption = $this->getDefaultOptions()->first();
        if ($defaultOption) {
            $field->setValue($defaultOption->Title);
        }

        $this->doUpdateFormField($field);
        return $field;
    }

    /**
     * Gets map of field options suitable for use in a form
     *
     * @return array
     */
    protected function getOptionsMap()
    {
        $optionSet = $this->CustomOptions();
        $optionMap = $optionSet->map('Title', 'Title');
        if ($optionMap instanceof Map ) {
            return $optionMap->toArray();
        }
        return $optionMap;
    }

    /**
     * Returns all default options
     *
     * @return SS_List
     */
    protected function getDefaultOptions()
    {
        return $this->CustomOptions()->filter('Default', 1);
    }

    /**
     * @return Boolean/Result
     */
    public function getValueFromData($data)
    {
        // if this field was set and there are lists - subscriper the user
        if (array_key_exists($this->Name, $data) && $this->getLists()->Count() > 0) {
            $this->extend('beforeValueFromData', $data);
            $auth = array(null, 'api_key' => $this->config()->get('api_key'));
            $wrap = new \CS_REST_Subscribers($this->owner->getField('ListID'), $auth);

            $custom_fields = $this->getCustomFields($data);
            if (empty($custom_fields)) { $custom_fields = array(); }

            $consent = 'no';
            $consentField = $this->owner->getField('ConsentField');
            if (isset($data[$consentField]) && !empty($data[$consentField])) {
                $consent = 'yes';
            }

            $dataToSend = array(
                'EmailAddress' => $data[$this->owner->getField('EmailField')],
                'Name' => $data[$this->owner->getField('FirstNameField')].' '.$data[$this->owner->getField('LastNameField')],
                'Resubscribe' => true,
                'CustomFields' => $custom_fields,
                'ConsentToTrack' => $consent
            );

            $result = $wrap->add($dataToSend);

            $this->extend('afterValueFromData', $result);

            if ($result->was_successful()) {
                return "Subscribed with code ".$result->http_status_code;
            } else {
                return "Not subscribed with code ".$result->http_status_code;
            }
        }

        return false;
    }

    /**
     * @return Boolean
     */
    public function getFieldValidationOptions()
    {
        return false;
    }

    /**
     * @return ArrayList
     */
    public function getLists()
    {
        $auth = array('api_key' => $this->config()->get('api_key'));
        $wrap = new \CS_REST_Clients($this->config()->get('client_id'), $auth);

        $result = $wrap->get_lists();
        $cLists = array();
        if ($result->was_successful()) {
            foreach ($result->response as $list) {
                $cLists[] = new ArrayData(array("ListID" => $list->ListID, "Name" => $list->Name));
            }
        }

        $this->extend('updateLists', $cLists);

        return new ArrayList($cLists);
    }

    /**
     * @return Array
     */
    public function getCustomFields(Array $data)
    {
        $custom_fields = array();
        // loop through the submitted data and check for custom fields
        foreach($data as $key=>$value){
            if(count(explode('customfield_',$key)) > 1){
                $custom_fields[] = array("Key" => substr($key, 12), "Value" => $value);
            }
        }

        $this->extend('updateCustomFields', $custom_fields);

        // check if any custom fields were found
        if(count($custom_fields) > 0) {
            return $custom_fields;
        }

        return array();
    }
}
