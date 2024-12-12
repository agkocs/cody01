<?php

namespace App\PaymentChannels\Drivers\Posfix;

use App\Models\Order;
use App\Models\PaymentChannel;
use App\PaymentChannels\BasePaymentChannel;
use App\Helpers\PosfixHelper;
use App\PaymentChannels\IChannel;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Exception;




class Channel extends BasePaymentChannel implements IChannel
{
    protected $debug = true;
    protected $privateKey;
    protected $publicKey;
    protected $test_mode;
    protected $currency;
    protected $Channelurl;
    protected $Version;
    protected $helper;

    

    protected array $credentialItems = [
        'privateKey',
        'publicKey',
        'Channelurl',
        'Version',
    ];

    /**
     * Channel constructor.
     * @param PaymentChannel $paymentChannel
     */
    public function __construct(PaymentChannel $paymentChannel)
    {
        $this->currency = currency();
        $this->order_session_key = 'posfix.payments.order_id';
        $this->setCredentialItems($paymentChannel);
        $this->helper = new PosfixHelper();

    }

public function paymentRequest(Order $order)
{
    ob_clean();
    
    try {
        $user = $order->user;
        session()->put($this->order_session_key, $order->id);

        $data = [
            'test_mode' => $this->test_mode,
            'privateKey' => $this->privateKey,
            'publicKey' => $this->publicKey,
            'Channelurl' => $this->Channelurl,
            'Version' => $this->Version,
            'orderId' => PosfixHelper::Guid (),
            'total_amount' => $order->total_amount * 100,
            'currency' => $this->currency,
            'method' => "post",
            'userData' => [
                'name' => $user->full_name,
                'address' => $user->address,
                'city' => $user->getRegionByTypeId($user->city_id),
                'postcode' => 123,
                'phone' => $user->mobile,
                'email' => $user->email,
            ]
        ];

        return view('web.default.cart.channels.posfix', $data);

    } catch (Exception $e) {
        \Log::error('PosfixChannel Error:', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'data' => $data ?? null
        ]);
        throw $e;
    }
}

    private function makeCallbackUrl($status)
    {
        return url("/payments/verify/Posfix?status=$status");
    }

    public function verify(Request $request)
    {
        $data = $request->all();
        $status = $data['status'];
        $transaction_id = $data['transaction_id'];

        $order_id = session()->get($this->order_session_key, null);
        session()->forget($this->order_session_key);

        $user = auth()->user();

        $order = Order::where('id', $order_id)
            ->where('user_id', $user->id)
            ->first();

        if (!empty($order)) {
            $orderStatus = Order::$fail;

            if ($status == 'success') {
                $url = '//posfix.com/?v_transaction_id=' . $transaction_id . '&type=json';
                if ($this->test_mode) {
                    $url .= '&demo=true';
                }

                $client = new Client();
                $response = $client->request('GET', $url);
                $obj = json_decode($response->getBody());

                if ($obj->response_message == 'Approved') {
                    $orderStatus = Order::$paying;
                }
            }

            $order->update([
                'status' => $orderStatus,
                'payment_data' => null,
            ]);
        }

        return $order;
    }

    protected function debugLog($message, $data = [])
    {
        if ($this->debug) {
            $logFile = storage_path('logs/posfix_' . date('Y-m-d') . '.log');
            $content = "[" . date('Y-m-d H:i:s') . "] " . $message . "\n";
            $content .= json_encode($data, JSON_PRETTY_PRINT) . "\n\n";
            file_put_contents($logFile, $content, FILE_APPEND);
        }
    }
}
