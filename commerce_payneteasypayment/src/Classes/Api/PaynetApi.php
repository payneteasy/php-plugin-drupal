<?php

namespace Drupal\commerce_payneteasypayment\Classes\Api;

use Drupal\commerce_payneteasypayment\Classes\Exception\PaynetEasyException;

class PaynetApi
{
    protected $url = 'paynet/api/v2/';

    public function __construct() {}

    public function saleForm($data, $action_url, $endpoint)
    {
        return $this->curlRequestHandler('sale-form/' . $endpoint, $data, $action_url);
    }

    public function saleDirect($data, $action_url, $endpoint)
    {
        return $this->curlRequestHandler('sale/' . $endpoint, $data, $action_url);
    }

    public function status($data, $action_url, $endpoint)
    {
        return $this->curlRequestHandler('status/' . $endpoint, $data, $action_url);
    }

    public function return($data, $action_url, $endpoint)
    {
        return $this->curlRequestHandler('return/' . $endpoint, $data, $action_url);
    }

    protected function curlRequestHandler($action, $data, $action_url)
    {
        $curl = curl_init($action_url.$this->url.$action);
        curl_setopt_array($curl, array
        (
            CURLOPT_HEADER         => 0,
            CURLOPT_USERAGENT      => 'Payneteasy-Client/1.0',
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSL_VERIFYPEER => 1,
            CURLOPT_POST           => 1,
            CURLOPT_RETURNTRANSFER => 1
        ));

        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($data));
        $response = curl_exec($curl);

        if(curl_errno($curl))
        {
            $error_message  = 'Error occurred: ' . curl_error($curl);
            $error_code     = curl_errno($curl);
        }
        elseif(curl_getinfo($curl, CURLINFO_HTTP_CODE) != 200)
        {
            $error_code     = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $error_message  = "Error occurred. HTTP code: '{$error_code}'";
        }

        curl_close($curl);

        if (!empty($error_message))
        {
            throw new PayneteasyException($error_message, [
                'response' => $error_code
            ]);
        }

        if(empty($response))
        {
            throw new PayneteasyException('Host response is empty', [
                'response' => $response
            ]);
        }

        $responseFields = array();
        parse_str($response, $responseFields);
        return $responseFields;
    }
}
