<?php
namespace App\Http\Controllers\Weixin;

use App\Http\Controllers\Weixin\CommonController;
use Illuminate\Http\Request;
use DB;
use Log;

class WxPayController extends CommonController
{
    public function __construct()
    {
        parent::__construct();
    }
	
    /**
     * 微信支付回调
     */
    public function wxpayNotify(Request $request)
	{
        $res = "SUCCESS"; //支付成功返回SUCCESS，失败返回FAILE
        
        //file_put_contents("1.txt",$GLOBALS['HTTP_RAW_POST_DATA']);
        Log::info('微信支付回调数据：'.$GLOBALS['HTTP_RAW_POST_DATA']);
        
        //获取通知的数据
		$xml = $GLOBALS['HTTP_RAW_POST_DATA'];
        //将XML转为array
        //禁止引用外部xml实体
        libxml_disable_entity_loader(true);
		$post_data = json_decode(json_encode(simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA)), true);
		
        if(isset($post_data['attach']) && !empty($post_data['attach']))
        {
            $get_arr = explode('&',$post_data['attach']);
            foreach($get_arr as $value)
            {
                $tmp_arr = explode('=',$value);
                $post_data[$tmp_arr[0]] = $tmp_arr[1];
            }
        }
        
        if($post_data['result_code'] == 'SUCCESS')
        {
            $pay_money = $post_data['total_fee']/100; //支付金额
            $pay_time_timestamp = strtotime(date_format(date_create($post_data['time_end']),"Y-m-d H:i:s")); //支付完成时间，时间戳格式
            $pay_time_date = date_format(date_create($post_data['time_end']),"Y-m-d H:i:s"); //支付完成时间，date格式Y-m-d H:i:s
            //$post_data['out_trade_no'] //商户订单号
            //$post_data['transaction_id'] //微信支付订单号
            
            //附加参数pay_type:1充值支付,2订单支付
            if($post_data['pay_type'] == 1)
            {
                //获取充值支付记录
                $user_recharge = DB::table('user_recharge')->where(array('recharge_sn'=>$post_data['out_trade_no'],'status'=>0))->first();
                if(!$user_recharge){Log::info('充值记录不存在');echo "FAILE";exit;}
                if($pay_money < $user_recharge->money){Log::info('充值金额不匹配');echo "FAILE";exit;} //如果支付金额小于要充值的金额
                
                //更新充值支付记录状态
                DB::table('user_recharge')->where(array('recharge_sn'=>$post_data['out_trade_no'],'status'=>0))->update(array('pay_time'=>$pay_time_timestamp,'updated_at'=>time(),'pay_type'=>1,'status'=>1,'trade_no'=>$post_data['transaction_id'],'pay_money'=>$pay_money));
                //增加用户余额
                DB::table('user')->where(array('id'=>$user_recharge->user_id))->increment('money', $pay_money);
                //添加用户余额记录
                DB::table('user_money')->insert(array('user_id'=>$user_recharge->user_id,'type'=>0,'money'=>$pay_money,'des'=>'充值','user_money'=>DB::table('user')->where(array('id'=>$user_recharge->user_id))->value('money'),'add_time'=>time()));
            }
            elseif($post_data['pay_type'] == 2)
            {
                //获取订单记录
                $order = DB::table('order')->where(array('order_sn'=>$post_data['out_trade_no'],'order_status'=>0,'pay_status'=>0))->first();
                if(!$order){Log::info('订单不存在');echo "FAILE";exit;}
                if($pay_money < $order->order_amount){Log::info('订单金额不匹配');exit;} //如果支付金额小于订单金额
                
                //修改订单状态
                $order_update_data['pay_status'] = 1;
                $order_update_data['pay_money'] = $pay_money; //支付金额
                $order_update_data['pay_id'] = 2;
                $order_update_data['pay_time'] = $pay_time_timestamp;
                $order_update_data['pay_name'] = 'wxpay_jsapi';
                $order_update_data['out_trade_no'] = $post_data['transaction_id'];
                $order_update_data['updated_at'] = time();
                
                DB::table('order')->where(array('order_sn'=>$post_data['out_trade_no'],'order_status'=>0,'pay_status'=>0))->update($order_update_data);
            }
            elseif($post_data['pay_type'] == 3)
            {
                $res = "FAILE";
            }
            elseif($post_data['pay_type'] == 4)
            {
                $res = "FAILE";
            }
            else
            {
                $res = "FAILE";
            }
            
            //file_put_contents("2.txt",$post_data['total_fee'].'--'.$post_data['out_trade_no'].'--'.$post_data['attach'].'--'.$post_data['pay_type']);
		}
        
        echo $res;
	}
    
    /**
     * 发微信红包
     * @param string $openid 用户openid
     */
    public function wxhbpay($re_openid,$money,$wishing='恭喜发财，大吉大利！',$act_name='赠红包活动',$remark='赶快领取您的红包！')
    {
		//微信支付-start
        require_once(resource_path('org/wxpay/WxPayConfig.php')); // 导入微信配置类
        require_once(resource_path('org/wxpay/WxPayPubHelper.class.php')); // 导入微信支付类
        
        $wxconfig= \WxPayConfig::wxconfig();
        $wxHongBaoHelper = new \Sendredpack($wxconfig);
        $wxHongBaoHelper->setParameter("mch_billno", date('YmdHis').rand(1000, 9999));//订单号
        $wxHongBaoHelper->setParameter("send_name", '红包');//红包发送者名称
        $wxHongBaoHelper->setParameter("re_openid", $re_openid);//接受openid
        $wxHongBaoHelper->setParameter("total_amount", floatval($money*100));//付款金额，单位分
        $wxHongBaoHelper->setParameter("total_num", 1);//红包収放总人数
        $wxHongBaoHelper->setParameter("wishing", $wishing);//红包祝福
        $wxHongBaoHelper->setParameter("client_ip", '127.0.0.1');//调用接口的机器 Ip 地址
        $wxHongBaoHelper->setParameter("act_name", $act_name);//活劢名称
        $wxHongBaoHelper->setParameter("remark", $remark);//备注信息
        $responseXml = $wxHongBaoHelper->postXmlSSL();
        //用作结果调试输出
        //echo htmlentities($responseXml,ENT_COMPAT,'UTF-8');
		$responseObj = simplexml_load_string($responseXml, 'SimpleXMLElement', LIBXML_NOCDATA);
		return $responseObj->result_code;
    }
    
    
}