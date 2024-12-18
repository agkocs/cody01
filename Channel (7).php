<?php

namespace App\PaymentChannels\Drivers\Posfix;

use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\RedirectionForm;
use Shetabit\Multipay\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\Request;
use App\Models\PaymentChannel;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use App\Helpers\ApiHelper;
use App\PaymentChannels\Drivers\Posfix\Api3DPaymentRequest;
use App\PaymentChannels\Drivers\Posfix\Helper;

class Channel extends Driver
{
    protected $invoice;
    protected $settings;
    protected $currency;
    protected $privateKey;
    protected $publicKey;
    protected $version;
    protected $test_mode;
    protected $apiUrl;
    protected $Language = "tr-TR";    


public function __construct($invoice, $settings = null)
{
    if ($invoice instanceof PaymentChannel) {
        $this->settings = is_array($invoice->credentials) 
            ? (object)$invoice->credentials 
            : json_decode($invoice->credentials);
    } else {
        $this->invoice = $invoice;
        $this->settings = (object)$settings;
    }

    $this->currency = currency();
    $this->privateKey = $this->settings->privateKey ?? '';
    $this->publicKey = $this->settings->publicKey ?? '';
    $this->version = $this->settings->version ?? '1.0';
    $this->test_mode = $this->settings->test_mode ?? 'off';
    $this->apiUrl = $this->settings->apiUrl ?? 'https://api.posfix.com.tr';
    $this->successUrl = "https://campusta.com/payments/verify/Posfix";
    $this->failureUrl = "https://api.posfix.com.tr/rest/payment/threed/test/result";
    $this->language = "tr-TR";


    
}

public function paymentRequest($order)
{
    $data = [
        'order' => $order,
        'settings' => $this->settings,
        'currency' => $this->currency
    ];

    return view('web.default.cart.channels.posfix', $data)->with([
        'order' => $order,
        'price' => $order->total_amount,
        'gate' => 'Posfix'
    ]);
}

    public function purchase()
    {
        
        
    }
    
    public function pay(): RedirectionForm
    {
        $response = $this->send3DRequest($this->invoice);
        
        if (isset($response['status']) && $response['status'] === 'success') {
            return $this->redirectWithForm(
                $response['url'],
                $response['data'] ?? [],
                'POST'
            );
        }

        throw new InvalidPaymentException('Posfix ödeme hatası');
    }

    public function send3DRequest($request)
    {
        $order = Order::findOrFail($request->order_id);
        $user = $order->user;

        // Kullanıcı adını ayrıştırma
        $fullName = $user->full_name;
        $nameParts = explode(' ', trim($fullName));
        $lastName = array_pop($nameParts);
        $firstName = implode(' ', $nameParts);

        // Ödeme verilerini hazırlama
        $payment = new Api3DPaymentRequest();
        $payment->card_holder = $request->card_holder;
        $payment->card_number = $request->card_number;
        $payment->expire_month = $request->expire_month;
        $payment->expire_year = $request->expire_year;
        $payment->cvv = $request->cvv;
        $payment->amount = $request->amount;
        $payment->order_id = $order->id;
        $payment->Echo = "Campusta Academy";
        $payment->purchaser = [
            'name' => $firstName,
            'surname' => $lastName,
            'email' => $user->email,
            'clientIp' => request()->ip()
        ];

        $payment->products = $order->orderItems->map(function ($item) {
            $itemDetails = null;

            if ($item->webinar_id) {
                $itemDetails = $item->webinar;
            } elseif ($item->product_id) {
                $itemDetails = $item->product;
            } elseif ($item->bundle_id) {
                $itemDetails = $item->bundle;
            } elseif ($item->reserve_meeting_id) {
                $itemDetails = $item->reserveMeeting;
            }

            return [
                'productCode' => $item->id,
                'productName' => $itemDetails ? $itemDetails->title : '',
                'quantity' => $item->amount * ($item->quantity ?? 1),
                'price' => $item->amount
            ];
        })->toArray();

        // Ödeme verilerini array'e dönüştürme
        
        $paymentData = [
            "mode" => $this->test_mode == 'on' ? 'T' : 'P',
            "orderId" => Helper::GUID(),
            "cardOwnerName" => $payment->card_holder,
            "cardNumber" => $payment->card_number,
            "cardExpireMonth" => $payment->expire_month,
            "cardExpireYear" => $payment->expire_year,
            "cardCvc" => $payment->cvv,
            "userId" => $user->id,
            "cardId" => "",
            "installment" => "1",
            "amount" => $payment->amount,
            "echo" => $payment->Echo,
            "vendorId" => "",
            "successUrl" => $this->successUrl,
            "failureUrl" => $this->failureUrl,
            "transactionDate" => Helper::GetTransactionDateString(),
            "version" => $this->version,
            "token" => "000000",
            "language" => $this->Language,
            "purchaser" => $payment->purchaser,
            "products" => $payment->products,
        ];

        // Helper fonksiyonlarını kullanarak verileri hazırlama
        $preparedData = Helper::PreparePaymentData($paymentData);
        
        // Ödeme verileri işleme
        $response = $this->execute3D($paymentData);
        return $response;
    }

    private function execute3D($paymentData)
    {
        // Token oluşturma işlemleri
        $settings = new \stdClass();

        $this->HashString = $this->privateKey . $paymentData['orderId'] . $paymentData['amount'] . $paymentData['mode'] . $paymentData['cardOwnerName'] . $paymentData['cardNumber'] . $paymentData['cardExpireMonth'] . $paymentData['cardExpireYear'] . $paymentData['cardCvc'] . $paymentData['userId'] . $paymentData['cardId'] . $paymentData['purchaser']['name'] . $paymentData['purchaser']['surname'] . $paymentData['purchaser']['email'] . $paymentData['transactionDate'];
        $paymentData['token'] = Helper::CreateToken($this->publicKey, $this->HashString);
        
        // JSON string ve URL oluşturma
        $parameters = json_encode($paymentData); // Alternatif olarak, $this->toJsonString($settings) kullanılabilir
        $url = $this->apiUrl . '/rest/payment/threed';

        // Formu HTML olarak döndürme
        return [
            'form' => $this->toHtmlString($parameters, $url),
            'preparedData' => $paymentData
        ];
    }
    

 public function toHtmlString($parameters, $url) {
        $builder = "";

        $builder .= "<!DOCTYPE HTML PUBLIC \"-//W3C//DTD HTML 4.0 Transitional//EN\">";
        $builder .= "<html>";
        $builder .= "<body>";
        $builder .= "<form action=\"https://api.posfix.com.tr/rest/payment/threed\" method=\"post\" id=\"three_d_form\" >";
        $builder .= "<input type=\"hidden\" name=\"parameters\" value=\"" . htmlspecialchars($parameters) . "\"/>";
        $builder .= "<input type=\"submit\" value=\"Öde\" style=\"display:none;\"/>";
        $builder .= "<noscript>";
        $builder .= "<br/>";
        $builder .= "<br/>";
        $builder .= "<center>";
        $builder .= "<h1>3D Secure Yönlendirme İşlemi</h1>";
        $builder .= "<h2>Javascript internet tarayıcınızda kapatılmış veya desteklenmiyor.<br/></h2>";
        $builder .= "<h3>Lütfen banka 3D Secure sayfasına yönlenmek için tıklayınız.</h3>";
        $builder .= "<input type=\"submit\" value=\"3D Secure Sayfasına Yönlen\">";
        $builder .= "</center>";
        $builder .= "</noscript>";
        $builder .= "</form>";
        $builder .= "</body>";
        $builder .= "<script>document.getElementById(\"three_d_form\").submit();</script>";
        $builder .= "</html>";
        return $builder;
    }

    public function printForm($form, $paymentData)
    {
        // Formu ve preparedData'yı ekrana yazdıran fonksiyon
        echo '<h2>Prepared Payment Data:</h2>';
        echo '<pre>';
        print_r($paymentData);
        echo '</pre>';
        echo $form;
        exit;
    }

    public function verify(): ReceiptInterface
    {
    if (!$this->invoice) {
        throw new InvalidPaymentException('Fatura bulunamadı');
    }

    $request = request();
    $result = new Api3DPaymentResult();
    $result->OrderId = $request->input('OrderId');
    $result->Result = $request->input('Result');
    $result->Amount = $request->input('Amount');
    $result->TransactionId = $request->input('TransactionId');
    
    if ($result->Result === '1') {
        return $this->createReceipt($result->TransactionId);
    }
    
    throw new InvalidPaymentException('Ödeme doğrulama başarısız');
}}
