<?php
defined('IN_ECJIA') or exit('No permission resources.');
/**
 *后台管理员给会员充值付款
 * @author 
 *
 */
class pay_module extends api_admin implements api_interface {
    public function handleRequest(\Royalcms\Component\HttpKernel\Request $request) {	
    	$this->authadminSession();
        
    	if ($_SESSION['staff_id'] <= 0) {
			return new ecjia_error(100, 'Invalid session');
		}
		
 		//变量初始化
 		$account_id = $this->requestData('account_id', 0);
 		$payment_id = $this->requestData('payment_id', 0);
	
 		if ($account_id <= 0 || $payment_id <= 0) {
	    	return new ecjia_error('invalid_parameter', '参数错误');
	    }
	    
	    //获取单条会员帐目信息
	    $order = array();
	    $order = get_surplus_info($account_id);
		
	    $plugin = new Ecjia\App\Payment\PaymentPlugin();
	    $payment_info = $plugin->getPluginDataById($payment_id);
	    
	    //对比支付方式pay_code；如果有变化，则更新支付方式
	    $pay_code   = $payment_info['pay_code'];
	    if (!empty($pay_code)) {
	    	if ($order['payment'] != $pay_code) {
	    		$payment_list = RC_Api::api('payment', 'available_payments');
	    		if (!empty($payment_list)) {
	    			foreach ($payment_list as $vv) {
	    				$pay_codes[] = $vv['pay_code'];
	    			}
	    			if (in_array($pay_code, $pay_codes)) {
	    				RC_DB::table('user_account')->where('id', $account_id)->update(array('payment' => $pay_code));
	    			}
	    		}
	    	}
	    }
	    
	    /* 如果当前支付方式没有被禁用，进行支付的操作 */
	    if (!empty($payment_info)) {
	    	$user_name = get_user_name($order['user_id']);
	    	$order['order_id']       = $order['id'];
	    	$order['user_name']      = $user_name;
	    	$order['surplus_amount'] = $order['amount'];
	    	//$order['open_id']	     = $wxpay_open_id;
	    	$order['order_type']     = 'user_account';
	        
	        RC_Loader::load_app_func('admin_order', 'orders');
	       	//计算支付手续费用
	        $payment_info['pay_fee'] = pay_fee($payment_id, $order['surplus_amount'], 0);
	        
	        //计算此次预付款需要支付的总金额
	        $order['order_amount']   = strval($order['surplus_amount'] + $payment_info['pay_fee']);
	        
	        $handler = $plugin->channel($payment_info['pay_code']);
	        $handler->set_orderinfo($order);
	        $handler->set_mobile(true);
	        $handler->setOrderType(Ecjia\App\Payment\PayConstant::PAY_SURPLUS);
	        $handler->setPaymentRecord(new Ecjia\App\Payment\Repositories\PaymentRecordRepository());
	        
	        $result = $handler->get_code(Ecjia\App\Payment\PayConstant::PAYCODE_PARAM);
	        if (is_ecjia_error($result)) {
	        	return $result;
	        } else {
	        	$order['payment'] = $result;
	        }
	         
	        return array('payment' => $order['payment']);
	        
	    } else {
	    	/* 重新选择支付方式 */
            return new ecjia_error('select_payment_pls_again', __('支付方式无效，请重新选择支付方式！'));
	    }
	}
}

/**
 * 获取会员充值申请信息
 *
 * @access  public
 * @param   int     $account_id  会员充值申请的ID
 *
 * @return  array
 */
function get_surplus_info($account_id = 0)
{
	$user_account_info = [];
	$user_account_info = RC_DB::table('user_account')->where('id', $account_id)->first();
	return $user_account_info;
}

/**
 * 获取会员名
 *
 * @access  public
 * @param   int     $user_id  会员的ID
 *
 * @return  string
 */
function get_user_name($user_id = 0)
{
	$user_name = '';
	$user_name = RC_DB::table('users')->where('user_id', $user_id)->pluck('user_name');
	return $user_name;
}

// end