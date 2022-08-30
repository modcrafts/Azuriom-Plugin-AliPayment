<?php

namespace Azuriom\Plugin\AliPayment;

use Azuriom\Plugin\Shop\Cart\Cart;
use Azuriom\Plugin\Shop\Models\Payment;
use Azuriom\Plugin\Shop\Payment\PaymentMethod;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AliPayMethod extends PaymentMethod
{
    /**
     * The payment method id name.
     *
     * @var string
     */
    protected $id = 'alipay-business';

    /**
     * The payment method display name.
     *
     * @var string
     */
    protected $name = '支付宝';

    public function startPayment(Cart $cart, float $amount, string $currency)
    {
        $payment = $this->createPayment($cart, $amount, $currency);

        $type = $this->isMobile() ? "alipay_wap" : "alipay_pc"; 
        $host = $this->gateway->data['host'];
        $param = urlencode(json_encode(array(
            "item_name" => $this->getPurchaseDescription($payment->id),
            "from" => 'Azuriom',
        )));

        $sign = md5($payment->id."FishPort 付款".$type.$amount.route('shop.payments.notification', $this->id).route('shop.payments.success', $this->id).$this->gateway->data['secret']);
        
        $attributes = array(
            "out_trade_no" => $payment->id,
            "subject" => "FishPort 付款",
            "type" => $type,
            "total_amount" => $amount,
            "notify_url" => route('shop.payments.notification', $this->id),
            "return_url" => route('shop.payments.success', $this->id),
            "sign" => $sign,
        );

        $response = Http::asForm()->post($host."/createOrder.php", $attributes);
        //var_dump($response->getBody());
        if (! $response->successful() || $response['status'] != "success") {
            $this->logInvalid($response, 'Invalid init response'.$response);

            return $this->errorResponse();
        }

        if ($response['sign'] != md5($response['content'].$this->gateway->data['secret'])){
            $this->logInvalid($response, 'Invalid sign');

            return $this->errorResponse();
        }
        $payment->update(['status' => 'pending']);
        //return response("success");
        
        return response(base64_decode($response['content']),200);
        //return redirect()->away($host.'/payPage/pay.html?'.Arr::query(["orderId"=>$response['data']['orderId']]));
    }

    private function isMobile() {
            // 如果有HTTP_X_WAP_PROFILE则一定是移动设备
        if (isset ($_SERVER['HTTP_X_WAP_PROFILE']))
        return true;

        // 如果via信息含有wap则一定是移动设备,部分服务商会屏蔽该信息
        if (isset ($_SERVER['HTTP_VIA']))
        {
        // 找不到为flase,否则为true
        return stristr($_SERVER['HTTP_VIA'], "wap") ? true : false;
        }
        // 脑残法，判断手机发送的客户端标志,兼容性有待提高
        if (isset ($_SERVER['HTTP_USER_AGENT']))
        {
            $clientkeywords = array ('nokia','sony','ericsson','mot','samsung','htc','sgh','lg','sharp','sie-','philips','panasonic','alcatel','lenovo','iphone','ipod','blackberry','meizu','android','netfront','symbian','ucweb','windowsce','palm','operamini','operamobi','openwave','nexusone','cldc','midp','wap','mobile');
            // 从HTTP_USER_AGENT中查找手机浏览器的关键字
            if (preg_match("/(" . implode('|', $clientkeywords) . ")/i", strtolower($_SERVER['HTTP_USER_AGENT'])))
                return true;
        }
            // 协议法，因为有可能不准确，放到最后判断
        if (isset ($_SERVER['HTTP_ACCEPT']))
        {
        // 如果只支持wml并且不支持html那一定是移动设备
        // 如果支持wml和html但是wml在html之前则是移动设备
            if ((strpos($_SERVER['HTTP_ACCEPT'], 'vnd.wap.wml') !== false) && (strpos($_SERVER['HTTP_ACCEPT'], 'text/html') === false || (strpos($_SERVER['HTTP_ACCEPT'], 'vnd.wap.wml') < strpos($_SERVER['HTTP_ACCEPT'], 'text/html'))))
            {
                return true;
            }
        }
                return false;
    } 

    public function notification(Request $request, ?string $rawPaymentId)
    {
        $payId = $request->input('out_trade_no');
        $orderId = $request->input('trade_no');
        //$param = $request->input('param');
        //$type = $request->input('type');
        $price = $request->input('total_amount');
        $reallyPrice = $request->input('receipt_amount');
        $status = $request->input('trade_status');
        $sign = $request->input('sign');

        
        /*if ($status === 'Expired') {
            $_sign = md5($orderId.$status.$this->gateway->data['secret']);
            if ($sign !== $_sign) {
                return response()->json('Invalid sign');
            }
            Payment::firstWhere('transaction_id',$orderId)->update(['status' => 'expired']);
            return response()->noContent();
        }*/
        
        $_sign = md5($payId.$orderId.$price.$reallyPrice.$status.$this->gateway->data['secret']);
        if($sign !== $_sign){
            logger()->warning("[Shop] Invalid notification sign: {$request} ".$payId.$orderId.$price.$reallyPrice.$status.$this->gateway->data['secret']);
            return response()->json('Invalid sign');
        }

        $payment = Payment::findOrFail($payId);

        if ($status !== 'TRADE_SUCCESS') {
            logger()->warning("[Shop] Invalid payment status for #{$payment->transaction_id}: {$status}");

            return $this->invalidPayment($payment, $orderId, 'Invalid status');
        }
        $payment->update(['transaction_id' => $orderId]);
        $this->processPayment($payment);
        return response("success")->header('Content-type','text/plain');
    }

    public function view()
    {
        return 'shop::admin.gateways.methods.alipay';
    }

    public function rules()
    {
        return [
            'host' => ['required', 'string'],
            'secret' => ['required', 'string'],
        ];
    }

    public function image()
    {
        return asset('plugins/alipayment/img/alipay-business.svg');
    }

    private function logInvalid(Response $response, string $message)
    {
        Log::warning("[Shop] AliPay - {$message} {$response->effectiveUri()} ({$response->status()}): {$response->json('msg')}");
    }
}
