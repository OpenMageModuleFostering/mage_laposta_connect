<?php

class Laposta_Connect_Helper_Sync extends Mage_Core_Helper_Abstract
{
    /**
     * @var array array(
     *     'type'       => Laposta_Connect_Helper_Laposta::FIELD_TYPE_TEXT,
     *     'options'    => array(),
     *     'default'    => '',
     *     'required'   => false,
     *     'showInForm' => false,
     *     'showInList' => true,
     * )
     */
    protected $fieldConfigMap = array(
        'dob'              => array(
            'type' => Laposta_Connect_Helper_Laposta::FIELD_TYPE_DATE,
        ),
        'gender'           => array(
            'type'    => Laposta_Connect_Helper_Laposta::FIELD_TYPE_SELECT_SINGLE,
            'options' => array('', 'Male', 'Female'),
        ),
        'store_id'         => array(
            'type' => Laposta_Connect_Helper_Laposta::FIELD_TYPE_NUMERIC,
        ),
        'website_id'       => array(
            'type' => Laposta_Connect_Helper_Laposta::FIELD_TYPE_NUMERIC,
        ),
        'date_of_purchase' => array(
            'type' => Laposta_Connect_Helper_Laposta::FIELD_TYPE_DATE,
        ),
        'group_id'         => array(
            'type' => Laposta_Connect_Helper_Laposta::FIELD_TYPE_NUMERIC,
        ),
    );

    /**
     * @var array
     */
    protected $defaultFieldConfig = array(
        'type'       => Laposta_Connect_Helper_Laposta::FIELD_TYPE_TEXT,
        'options'    => array(),
        'default'    => '',
        'required'   => false,
        'showInForm' => false,
        'showInList' => true,
    );

    /**
     * @return string
     */
    protected function resolveApiKey()
    {
        return Mage::helper('lapostaconnect')->config('api_key');
    }

    /**
     * Sync the list configuration with Laposta
     *
     * @param Laposta_Connect_Model_List $list
     *
     * @return $this
     */
    public function syncList(Laposta_Connect_Model_List $list)
    {
        $this->log(__METHOD__, "Starting to sync list: " . $list->getListName());

        if (Mage::helper('lapostaconnect')->config('active') !== '1') {
            return $this;
        }

        if (strtotime($list->getUpdatedTime()) <= strtotime($list->getSyncTime())) {
            return $this;
        }

        /** @var $laposta Laposta_Connect_Helper_Laposta */
        $laposta = Mage::helper('lapostaconnect/laposta');
        $laposta->setApiToken($this->resolveApiKey());

        $lapostaId = $list->getLapostaId();
        $listName  = $list->getListName();

        if (empty($listName)) {
            $listName = '(Empty List Name - Magento)';

            $this->log('Creating new list', $listName);

            $list->setListName($listName);
        }

        if (empty($lapostaId)) {
            $this->log("Creating new list", $listName);

            $lapostaId = $laposta->addGroup($listName);

            $list->setLapostaId($lapostaId);
        }
        else {
            $this->log('Updating list', array('listName' => $listName, 'lapostId' => $lapostaId));

            $laposta->updateGroup($lapostaId, $listName);
        }

        $this->resetWebhooks($list);

        $list->setSyncTime(date('Y-m-d H:i:s'));

        return $this;
    }

    /**
     * Reset the webhooks with a new token
     *
     * @param Laposta_Connect_Model_List $list
     *
     * @return $this
     */
    protected function resetWebhooks(Laposta_Connect_Model_List $list)
    {
        /** @var $laposta Laposta_Connect_Helper_Laposta */
        $laposta = Mage::helper('lapostaconnect/laposta');
        $laposta->setApiToken($this->resolveApiKey());

        $lapostaListId = $list->getLapostaId();
        $current       = $laposta->getHooks($lapostaListId, Mage::getBaseUrl());
        $token         = $list->getData('webhook_token');

        if (empty($token)) {
            $token = base_convert(rand(PHP_INT_MAX / 3, PHP_INT_MAX), 10, 36);
        }

        $hookUrl = Mage::getBaseUrl() . 'lapostaconnect/webhook?t=' . $token;

        foreach ($current as $hookData) {
            if (!isset($hookData['webhook']['webhook_id'])) {
                continue;
            }

            $laposta->removeHook($lapostaListId, $hookData['webhook']['webhook_id']);
        }

        $laposta->addHook($lapostaListId, $hookUrl);

        $list->setWebhookToken($token);

        return $this;
    }

    /**
     * Sync fields with laposta
     *
     * @param Laposta_Connect_Model_List                    $list
     * @param Laposta_Connect_Model_Mysql4_Field_Collection $fields
     *
     * @return $this
     */
    public function syncFields(
        Laposta_Connect_Model_List $list,
        Laposta_Connect_Model_Mysql4_Field_Collection $fields
    ) {
        if (Mage::helper('lapostaconnect')->config('active') !== '1') {
            return $this;
        }

        $lapostaListId = $list->getLapostaId();

        if (empty($lapostaListId) || $fields->count() === 0) {
            return $this;
        }

        /** @var $laposta Laposta_Connect_Helper_Laposta */
        $laposta = Mage::helper('lapostaconnect/laposta');
        $laposta->setApiToken($this->resolveApiKey());

        $current      = $this->resolveCurrentFields($lapostaListId);
        $synchronised = array();

        /** @var $field Laposta_Connect_Model_Field */
        foreach ($fields as $field) {
            $lapostaFieldId = $field->getLapostaId();
            $synchronised[] = $lapostaFieldId;

            if (!empty($lapostaFieldId) && strtotime($field->getUpdatedTime()) <= strtotime($field->getSyncTime())
            ) {
                continue;
            }

            $fieldName     = $field->getFieldName();
            $fieldConfig   = $this->resolveFieldConfig($fieldName);
            $fieldRelation = $field->getFieldRelation();

            // TODO: Use field type and field options resolver
            if (empty($lapostaFieldId)) {
                $result = $laposta->addField(
                    $lapostaListId,
                    $fieldRelation,
                    $fieldConfig['type'],
                    $fieldConfig['options'],
                    $fieldConfig['default'],
                    $fieldConfig['required'],
                    $fieldConfig['showInForm'],
                    $fieldConfig['showInList']
                );

                $lapostaFieldId  = $result['id'];
                $lapostaFieldTag = $result['tag'];

                $field->setLapostaId($lapostaFieldId);
            }
            else {
                $lapostaFieldTag = $laposta->updateField(
                    $lapostaListId,
                    $lapostaFieldId,
                    $fieldRelation,
                    $fieldConfig['type'],
                    $fieldConfig['options'],
                    $fieldConfig['default'],
                    $fieldConfig['required'],
                    $fieldConfig['showInForm'],
                    $fieldConfig['showInList']
                );
            }

            $field->setLapostaTag(trim($lapostaFieldTag, '{}'));
            $field->setSyncTime(date('Y-m-d H:i:s'));
        }

        $remove = array_diff($current, $synchronised);

        foreach ($remove as $lapostaFieldId) {
            $laposta->removeField($lapostaListId, $lapostaFieldId);
        }

        return $this;
    }

    /**
     * Resolve the field configuration
     *
     * @param string $fieldName
     *
     * @return array
     */
    protected function resolveFieldConfig($fieldName)
    {
        $result = $this->defaultFieldConfig;

        if (isset($this->fieldConfigMap[$fieldName])) {
            $result = array_replace($result, $this->fieldConfigMap[$fieldName]);
        }

        return $result;
    }

    /**
     * Get the list of fields registered with Laposta for the given list id.
     *
     * @param string $lapostaListId
     *
     * @return array
     */
    protected function resolveCurrentFields($lapostaListId)
    {
        /** @var $laposta Laposta_Connect_Helper_Laposta */
        $laposta = Mage::helper('lapostaconnect/laposta');
        $laposta->setApiToken($this->resolveApiKey());

        $current = $laposta->getFields($lapostaListId);

        if (empty($current) || !is_array($current)) {
            return array();
        }

        $result = array();

        foreach ($current as $field) {
            if (!isset($field['field']['field_id']) || !empty($field['field']['is_email'])) {
                continue;
            }

            $result[] = $field['field']['field_id'];
        }

        return $result;
    }

    /**
     * Resolve the list of status codes considered active
     *
     * @return array
     */
    protected function resolveSubscriberStatusWhiteList()
    {
        $statusWhiteList = array(Mage_Newsletter_Model_Subscriber::STATUS_SUBSCRIBED);

        if (Mage::helper('lapostaconnect')->config('subscribe_unconfirmed') === '1') {
            $statusWhiteList[] = Mage_Newsletter_Model_Subscriber::STATUS_UNCONFIRMED;
        }

        return $statusWhiteList;
    }

    /**
     * Synchronise the subscribers
     *
     * @param Laposta_Connect_Model_Mysql4_Subscriber_Collection $subscribers
     *
     * @return $this
     */
    public function syncSubscribers(
        Laposta_Connect_Model_Mysql4_Subscriber_Collection $subscribers
    ) {
        if (Mage::helper('lapostaconnect')->config('active') !== '1') {
            return $this;
        }

        /** @var $laposta Laposta_Connect_Helper_Laposta */
        $laposta = Mage::helper('lapostaconnect/laposta');
        $laposta->setApiToken($this->resolveApiKey());

        /** @var $lists Laposta_Connect_Model_Mysql4_List_Collection */
        $lists     = Mage::getModel('lapostaconnect/list')->getCollection();
        $listIdMap = array_combine(
            $lists->getColumnValues('list_id'),
            $lists->getColumnValues('laposta_id')
        );

        foreach ($listIdMap as $lapostaListId) {
            $laposta->disableHooks($lapostaListId, Mage::getBaseUrl());
        }

        /** @var $fieldsHelper Laposta_Connect_Helper_Fields */
        $fieldsHelper = Mage::helper('lapostaconnect/Fields');

        /** @var $nativeSubscribers Mage_Newsletter_Model_Mysql4_Subscriber_Collection */
        $nativeSubscribers = Mage::getModel('newsletter/subscriber')->getCollection();

        /** @var $subscriber Laposta_Connect_Model_Subscriber */
        foreach ($subscribers as $subscriber) {
            $customerId         = $subscriber->getCustomerId();
            $lapostaMemberId    = $subscriber->getLapostaId();
            $nativeSubscriberId = $subscriber->getNewsletterSubscriberId();
            $lapostaListId      = $listIdMap[$subscriber->getListId()];
            $nativeSubscriber   = $nativeSubscribers->getItemById($nativeSubscriberId);

            if (!$nativeSubscriber instanceof Mage_Newsletter_Model_Subscriber || $nativeSubscriber->isEmpty()) {
                $nativeSubscriber = $nativeSubscribers->getItemByColumnValue('customer_id', $customerId);
            }

            if ((empty($customerId) && empty($nativeSubscriberId)) || !$nativeSubscriber instanceof Mage_Newsletter_Model_Subscriber || $nativeSubscriber->isEmpty()) {
                if (!empty($lapostaMemberId)) {
                    $laposta->removeContact($lapostaListId, $lapostaMemberId);
                }

                $subscriber->delete();

                continue;
            }

            /*
             * Resolve the subscriber core data values
             */

            $email      = $nativeSubscriber->getEmail();
            $subscribed = in_array($nativeSubscriber->getData('subscriber_status'), $this->resolveSubscriberStatusWhiteList()) ? true : false;
            $data       = array();

            if ($customerId != "0") {
                /** @var $customer Mage_Customer_Model_Customer */
                $customer = Mage::getModel('customer/customer')->load($customerId);
                /** @var $customerHelper Laposta_Connect_Helper_Customer */
                $customerHelper = Mage::helper('lapostaconnect/customer');
                $customerHelper->setCustomer($customer);

                $fields = $fieldsHelper->getByListId($subscriber->getListId());
                $data   = array_combine(
                    array_values($fields),
                    array_values($customerHelper->resolve(array_keys($fields)))
                );
            }

            if (empty($lapostaMemberId)) {
                $subscriber->setData(
                    'laposta_id',
                    $laposta->addContact($lapostaListId, '', $email, $data, $subscribed)
                );
            }
            else {
                $laposta->updateContact($lapostaListId, $lapostaMemberId, '', $email, $data, $subscribed);
            }

            $subscriber->setSyncTime(date('Y-m-d H:i:s'));
            $subscriber->save();
        }

        foreach ($listIdMap as $lapostaListId) {
            $laposta->enableHooks($lapostaListId, Mage::getBaseUrl());
        }

        return $this;
    }

    protected function log($method, $message, $result = array())
    {
        $logData = array(
            'method'  => $method,
            'message' => $message,
        );

        if (!empty($result)) {
            $logData['result'] = $result;
        }

        Mage::helper('lapostaconnect')->log($logData);
    }
}
