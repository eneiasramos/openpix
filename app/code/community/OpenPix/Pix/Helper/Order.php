<?php

class OpenPix_Pix_Helper_Order extends Mage_Core_Helper_Abstract {

    use OpenPix_Pix_Trait_ExceptionMessenger;
    use OpenPix_Pix_Trait_LogMessenger;

    public function getStoreName() {
        $store = Mage::app()->getStore();
        return $store->getName();
    }

    public function formatPhone($phone) {
        if(strlen($phone) > 11) {
            return preg_replace('/^0|\D+/', '', $phone);
        }

        return '55' . preg_replace('/^0|\D+/', '', $phone);
    }

    public function getTaxID($quote) {
        $taxID = $quote->getCustomerTaxvat() ? $quote->getCustomerTaxvat() : '';
        $isValidCPF = $this->helper()->validateCPF($taxID);
        $isValidCNPJ = $this->helper()->validateCNPJ($taxID);

        if($isValidCPF || $isValidCNPJ) {
            return $taxID;
        }

        return null;
    }

    public function getCustomerData($quote, $order) {
        try {
            $taxID = $this->getTaxID($quote);
            $name = $this->helper()->getCustomerNameFromQuote($quote);
            $email = $quote->getCustomerEmail();
            $phone = $order->getShippingAddress()->getTelephone();

            if(!taxID && !email && !phone) {
                return null;
            }

            $customer = [
                "name" => $name,
                "email" => $email,
                "phone" => $this->formatPhone($phone),
            ];

            if(!$taxID) {
                return $customer;
            }

            $customer = [
                "taxID" => $quote->getCustomerTaxvat(),
                "name" => $name,
                "email" => $email,
                "phone" => $this->formatPhone($phone),
            ];

            return $customer;
        } catch (Exception $e) {
            $this->log('Fail when getting customer data: ' . $e->getMessage());
            return false;
        }
    }

    public function handlePayloadCharge($orderId){
        $order = Mage::getModel('sales/order')->loadByIncrementId($orderId);
        $quote = Mage::getSingleton('checkout/session')->getQuote();

        if ($order && $order->getId()) {
            $correlationID = $this->helper()->uuid_v4();

            $grandTotal = $order->getGrandTotal();

            $storeName = $this->getStoreName();

            $customer = $this->getCustomerData($quote, $order);

            $additionalInfo = [
                [
                    'key' => 'Pedido',
                    'value' => $orderId
                ]
            ];

            $comment = substr("$storeName", 0, 100) . '#' . $orderId;
            $comment_trimmed = substr($comment, 0, 140);

            if(!$customer) {
                return [
                    'correlationID' => $correlationID,
                    'value' => $this->helper()->get_amount_openpix($grandTotal),
                    'comment' => $comment_trimmed,
                    'additionalInfo' => $additionalInfo
                ];
            }

            $payload = [
                'correlationID' => $correlationID,
                'value' => $this->helper()->get_amount_openpix($grandTotal),
                'comment' => $comment_trimmed,
                'customer' => $customer,
                'additionalInfo' => $additionalInfo
            ];

            return $payload;
        }

        return [];
    }

    public function handleCreateCharge($data) {
        $app_ID = $this->helper()->getAppID();

        if (!$app_ID) {
            $this->log("OpenPix - AppID not found");
            $this->error("An error occurred while creating your order");
            return false;
        }

        $apiUrl = $this->helper()->getOpenPixApiUrl();

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json; charset=utf-8',
            "Authorization: " . $app_ID,
            "platform: MAGENTO1",
            "version: 1.1.5"
        ];

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $apiUrl . '/api/openpix/v1/charge');
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));

        $response = curl_exec($curl);
        $statusCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        if (curl_errno($curl) || $response === false) {
            $this->log("OpenPix Curl Error - while creating Pix " . json_encode($response));
            $this->error("An error occurred while creating your order");
            curl_close($curl);

            return false;
        }

        curl_close($curl);

        if ($statusCode === 401) {
            $this->log("OpenPix Error 401 - Invalid AppID");
            $this->error("An error occurred while creating your order");

            return false;
        }

        if ($statusCode === 400) {
            $this->log("OpenPix Error 400 - " . json_encode($response));
            $this->error("An error occurred while creating your order");

            return false;
        }

        if ($statusCode !== 200) {
            $this->log("OpenPix Error 400 - while creating Pix " . json_encode($response));
            $this->error("An error occurred while creating your order");
            curl_close($curl);

            return false;
        }

        return json_decode($response, true);
    }

    public function handleResponseCharge($responseBody, $orderId, $payment) {
        $order = Mage::getModel('sales/order')->loadByIncrementId($orderId);

        $order->setOpenpixCorrelationid($responseBody["charge"]["correlationID"]);
        $order->setOpenpixPaymentlinkurl($responseBody["charge"]["paymentLinkUrl"]);
        $order->setOpenpixQrcodeimage($responseBody["charge"]["qrCodeImage"]);
        $order->setOpenpixBrcode($responseBody["charge"]["brCode"]);

        $order->save();

        $payment->setAdditionalInformation('openpix_correlationid', $responseBody["charge"]["correlationID"]);
        $payment->setAdditionalInformation('openpix_paymentlinkurl', $responseBody["charge"]["paymentLinkUrl"]);
        $payment->setAdditionalInformation('openpix_qrcodeimage', $responseBody["charge"]["qrCodeImage"]);
        $payment->setAdditionalInformation('openpix_brcode', $responseBody["charge"]["brCode"]);

        $additional = $payment->getAdditionalInformation();

        return ['success' => true, 'additional' => $additional];
    }

    public function addInformation($order, $additional){
        if($order && $order->getId() && is_array($additional) && count($additional) >= 1){
            foreach ($additional as $key => $value) {
                $order->getPayment()->setAdditionalInformation($key, $value)->save();
            }
        }
    }

    protected function helper(){
        return Mage::helper('openpix_pix');
    }
}