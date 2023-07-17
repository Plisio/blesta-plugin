<?php
class Plisio extends NonmerchantGateway
{
    private $meta;
    public function __construct()
    {
        $this->loadConfig(dirname(__FILE__) . DS . 'config.json');

        Loader::loadComponents($this, ['Input']);

        Loader::loadModels($this, ['Clients']);

        Language::loadLang('plisio', null, dirname(__FILE__) . DS . 'language' . DS);
    }

    /**
     * {@inheritdoc}
     */
    public function setCurrency($currency)
    {
        $this->currency = $currency;
    }

    /**
     * {@inheritdoc}
     */
    public function getSettings(array $meta = null)
    {
        $this->view = $this->makeView('settings', 'default', str_replace(ROOTWEBDIR, '', dirname(__FILE__) . DS));

        Loader::loadHelpers($this, ['Form', 'Html']);

        $this->view->set('meta', $meta);

        return $this->view->fetch();
    }

    /**
     * {@inheritdoc}
     */
    public function editSettings(array $meta)
    {
        $rules = [
            'api_key'    => [
                'empty' => [
                    'rule'    => 'isEmpty',
                    'negate'  => true,
                    'message' => Language::_('Plisio.!error.api_key.empty', true),
                ],
            ],
        ];

        $this->Input->setRules($rules);

        $this->Input->validates($meta);

        return $meta;
    }

    /**
     * {@inheritdoc}
     */
    public function encryptableFields()
    {
        return array('api_key');
    }

    /**
     * {@inheritdoc}
     */
    public function setMeta(array $meta = null)
    {
        $this->meta = $meta;
    }

    /**
     * {@inheritdoc}
     */
    public function buildProcess(array $contactInfo, $amount, array $invoiceAmounts = null, array $options = null)
    {
        Loader::load(dirname(__FILE__) . DS . 'init.php');

        $clientId = $contactInfo['client_id'] ?? null;
        $clientEmail = $contactInfo['email'] ?? null;

        $orderNumber = "$clientId@" . time();

        $invoices = $this->serializeInvoices($invoiceAmounts);

        $callbackURL = Configure::get('Blesta.gw_callback_url') . '?client_id=' . ($contactInfo['client_id'] ?? null) . '&invoices=' . ($invoices ?? null);

        $client = new Plisio\PlisioClient($this->meta['api_key']);

        $params = [
            'order_name' => 'Order#' . $orderNumber,
            'order_number' => $orderNumber,
            'source_amount' => $amount,
            'source_currency' => $this->currency,
            'callback_url' => $callbackURL,
            'cancel_url' => $options['return_url'] ?? null,
            'success_url' => $options['return_url'] ?? null,
            'email' => $clientEmail,
            'plugin' => 'Blesta',
            'version' => $this->getVersion(),
        ];

        $response = $client->createTransaction($params);

        if ($response && $response['status'] !== 'error' && !empty($response['data'])) {
            header('Location: ' . $response['data']['invoice_url']);
        } else {
            $this->Input->setErrors(['exception' => ['message' => implode(',', json_decode($response['data']['message'], true))]]);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function validate(array $get, array $post)
    {
        $invoices = $this->unserializeInvoices($get['invoices']);
        $this->log('plisio', print_r($get, 1), 'input', 'false');
        $this->log('plisio', print_r($invoices, 1), 'input', 'false');

        $clientId = $get['client_id'];

        $orderNumber = $post['order_number'];

        $blestaStatus = $this->statusChecking($post['status']);

        return [
            'client_id'      => $clientId,
            'amount'         => $post['source_amount'],
            'currency'       => $post['source_currency'],
            'status'         => $blestaStatus,
            'reference_id'   => null,
            'transaction_id' => $orderNumber,
            'invoices'       => $invoices,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function success(array $get, array $post)
    {
        $this->Input->setErrors($this->getCommonError("unsupported"));
    }

    /**
     * Serializes an array of invoice info into a string
     *
     * @param array A numerically indexed array invoices info including:
     *  - id The ID of the invoice
     *  - amount The amount relating to the invoice
     * @return string A serialized string of invoice info in the format of key1=value1|key2=value2
     */
    private function serializeInvoices(array $invoices)
    {
        $str = '';
        foreach ($invoices as $i => $invoice) {
            $str .= ($i > 0 ? '|' : '') . $invoice['id'] . '=' . $invoice['amount'];
        }

        return $str;
    }

    /**
     * Unserializes a string of invoice info into an array
     *
     * @param string A serialized string of invoice info in the format of key1=value1|key2=value2
     * @return array A numerically indexed array invoices info including:
     *  - id The ID of the invoice
     *  - amount The amount relating to the invoice
     */
    private function unserializeInvoices($str)
    {
        $invoices = [];
        $temp = explode('|', $str);
        foreach ($temp as $pair) {
            $pairs = explode('=', $pair, 2);
            if (count($pairs) != 2) {
                continue;
            }
            $invoices[] = ['id' => $pairs[0], 'amount' => $pairs[1]];
        }

        return $invoices;
    }

    public function statusChecking($plisioStatus)
    {
        switch ($plisioStatus) {
            case 'new':
                $status = 'pending';
                break;
            case 'completed':
            case 'mismatch':
                $status = 'approved';
                break;
            case 'expired':
            case 'cancelled':
                $status = 'declined';
                break;
            default:
                $status = 'pending';
        }

        return $status;
    }

    public function verifyCallbackData($post, $apiKey)
    {
        if (!isset($post['verify_hash'])) {
            return false;
        }

        $verifyHash = $post['verify_hash'];
        unset($post['verify_hash']);
        ksort($post);
        if (isset($post['expire_utc'])){
            $post['expire_utc'] = (string)$post['expire_utc'];
        }
        if (isset($post['tx_urls'])){
            $post['tx_urls'] = html_entity_decode($post['tx_urls']);
        }
        $postString = serialize($post);
        $checkKey = hash_hmac('sha1', $postString, $apiKey);
        if ($checkKey != $verifyHash) {
            return false;
        }

        return true;
    }
}
