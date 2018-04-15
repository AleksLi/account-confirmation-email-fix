<?php

namespace Noveo\AccountConfirmationEmailFix\Model;

use Magento\Customer\Api\AccountManagementInterface;
use Magento\Framework\Exception\MailException;
use Magento\Framework\Exception\NoSuchEntityException;

class Subscriber extends \Magento\Newsletter\Model\Subscriber
{
    /**
     * Saving customer subscription status
     *
     * @param int $customerId
     * @param bool $subscribe indicates whether the customer should be subscribed or unsubscribed
     * @return  $this
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    protected function _updateCustomerSubscription($customerId, $subscribe)
    {
        try {
            $customerData = $this->customerRepository->getById($customerId);
        } catch (NoSuchEntityException $e) {
            return $this;
        }

        $this->loadByCustomerId($customerId);
        if (!$subscribe && !$this->getId()) {
            return $this;
        }

        if (!$this->getId()) {
            $this->setSubscriberConfirmCode($this->randomSequence());
        }

        $sendInformationEmail = false;
        $status = self::STATUS_SUBSCRIBED;
        $isConfirmNeed = $this->_scopeConfig->getValue(
            self::XML_PATH_CONFIRMATION_FLAG,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        ) == 1 ? true : false;
        if ($subscribe) {
            if (AccountManagementInterface::ACCOUNT_CONFIRMATION_REQUIRED
                == $this->customerAccountManagement->getConfirmationStatus($customerId)
            ) {
                $status = self::STATUS_UNCONFIRMED;
            } elseif ($isConfirmNeed) {
                $status = self::STATUS_NOT_ACTIVE;
            }
        } elseif (($this->getStatus() == self::STATUS_UNCONFIRMED) && ($customerData->getConfirmation() === null)) {
            $status = self::STATUS_SUBSCRIBED;
            $sendInformationEmail = true;
        } else {
            $status = self::STATUS_UNSUBSCRIBED;
        }
        /**
         * If subscription status has been changed then send email to the customer
         */
        if ($status != self::STATUS_UNCONFIRMED && $status != $this->getStatus()) {
            $sendInformationEmail = true;
        }

        if ($status != $this->getStatus()) {
            $this->setStatusChanged(true);
        }

        $this->setStatus($status);

        if (!$this->getId()) {
            $storeId = $customerData->getStoreId();
            if ($customerData->getStoreId() == 0) {
                $storeId = $this->_storeManager->getWebsite($customerData->getWebsiteId())->getDefaultStore()->getId();
            }
            $this->setStoreId($storeId)
                ->setCustomerId($customerData->getId())
                ->setEmail($customerData->getEmail());
        } else {
            $this->setStoreId($customerData->getStoreId())
                ->setEmail($customerData->getEmail());
        }

        $this->save();
        $sendSubscription = $sendInformationEmail;
        if ($sendSubscription === null xor $sendSubscription && $this->isStatusChanged()) {
            try {
                switch ($status) {
                    case self::STATUS_UNSUBSCRIBED:
                        $this->sendUnsubscriptionEmail();
                        break;
                    case self::STATUS_SUBSCRIBED:
                        $this->sendConfirmationSuccessEmail();
                        break;
                    case self::STATUS_NOT_ACTIVE:
                        if ($isConfirmNeed) {
                            $this->sendConfirmationRequestEmail();
                        }
                        break;
                }
            } catch (MailException $e) {
                // If we are not able to send a new account email, this should be ignored
                $this->_logger->critical($e);
            }
        }
        return $this;
    }
}