<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Payment;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Requests\UpdateOrderRequest;
use App\Models\ShopLoginCred; 
use App\Models\UserLoginCred; 
use Illuminate\Http\Request;
use App\Http\Controllers\PaymentController;
use DB;
use Response;
use Illuminate\Http\JsonResponse;
use Carbon\Carbon;
use Razorpay\Api\Api;

class OrderController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
    }

    public function ordersFetchCompleted(){
        return Order::where('status', 'completed')->get();
    }

    public function ordersFetchPending(){
        return Order::where('status', 'pending')->get();
    }

    public function ordersFetchAll(){
        return Order::all();
    }

    public function ordersFetch($shid){
        return Order::where('shid', $shid)->get();
    }

    public function ordersFetchProcessed($shid){
        $amount = Order::where('shid', $shid)->where('status', 'completed')->sum('total_cost');
        return $amount;
    }

    /**
     * Show the form for creating a new resource.
     *
     * 
     */
    public function place(Request $request){
        $request->validate([
            'cloth_order_id'=>'required',
            'payment_id'=>'required',
            'razorpay_payment_id'=>'required',
            'razorpay_order_id'=>'required',
            'razorpay_signature'=>'required',
        ]);

        $user = ShopLoginCred::where('shid', $request->shid)->first();
            if(!$user){
                return Response::json(['error'=>['ShId is not valid'],422]);
            }

        $user = UserLoginCred::where('uid', $request->uid)->first();
        if(!$user){
            return Response::json(['error'=>['UId is not valid'],422]);
        }



        $generated_signature = hmac_sha256($request->razorpay_order_id
         + "|" + $request->razorpay_payment_id, $secret);

    

        if ($generated_signature == $request->razorpay_signature) {
            $payment = Payment::where('payment_id', $request->payment_id)->first();

            $payment->razorpay_payment_id = $request->razorpay_payment_id;
            $payment->razorpay_order_id = $request->razorpay_order_id;
            $payment->razorpay_signature = $request->razorpay_signature;
            $payment->status = "Completed";

            $order = Order::where('order_id', $request->order_id)->first();
            $order->status = "Confirm";
            



            $check = $payment->save();
            $check2 = $order->save();




            if($check && $check2){
                return Response::json(["status"=>'Order Placed ',"error"=>"{$e}"],500);

            }else{
                return Response::json(["status"=>'Order Confirm'],200);
            }


            
        }else{
            return Response::json(["status"=>'Order Not Confirm, Something Wrong ',"error"=>"{$e}"],500);
        }


        

    }
    
    public function store(Request $request)
    {

        $request->validate([
            'uid'=>'required',
            'shid'=>'required',
            'pickup_dt'=>'required',
            'delivery_dt'=>'required',
            'geolocation'=>'required',
            'address'=>'required',
            'status'=>'required',
            'service_type'=>'required',
            'total_cost'=>'required',
            'clothes_types'=>'required',
            'express'=>'required',
        ]);

            $user = ShopLoginCred::where('shid', $request->shid)->first();
            if(!$user){
                return Response::json(['error'=>['ShId is not valid'],422]);
            }

            $user = UserLoginCred::where('uid', $request->uid)->first();
            if(!$user){
                return Response::json(['error'=>['UId is not valid'],422]);
            }
            

            $user=Order::where('order_id', $request->shid)->first();

            //create new order model instance
            $order_id="order_id".sha1(time());
            $new_user=new Order();
            $new_user->shid=$request->shid;
            $new_user->uid=$request->uid;
            $new_user->order_id=$order_id;
            $new_user->pickup_dt=$request->pickup_dt;
            $new_user->delivery_dt=$request->delivery_dt;
            $new_user->geolocation=$request->geolocation;
            $new_user->address=$request->address;
            $new_user->status=$request->status;
            $new_user->total_cost=$request->total_cost;
            $new_user->clothes_types=json_encode($request->clothes_types);
            $new_user->service_type=$request->service_type;
            $new_user->express=filter_var($request->express, FILTER_VALIDATE_BOOLEAN);
                
            DB::beginTransaction();
            $result = new JsonResponse();
            try{
                $result=(new PaymentController)->index(new Request([
                    'total_amount'=>$request->total_cost,
                    'cloth_order_id'=>$order_id,
                ]));
                
                if(!$result) return Response::json(["status"=>'Order Not Placed',"error"=>"{$e}"],500);
                
            }
             catch (Exception $e) {

                DB::rollback();
                
                return Response::json(["status"=>'Order Not Placed',"error"=>"{$e}"],500);
                
            }
            $temp = $result->getData();
            $new_user->payment_id = $temp->pid;
            
            $check_user=$new_user->save();
            DB::commit();
            if($check_user){
                $response = [
                    "status"=>"Order Placed, Payment Initiated",
                    "cloth_order_id"=>$order_id,
                    "payment_id"=>$temp->pid,
                    "payment_order_id"=>$temp->order_id,
                ];
                return Response::json($response,200);
            }else{
                $response = [
                    "status"=>"Order Failed",
                ];
                return Response::json($response,200);
            }


    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Order  $order
     * @return \Illuminate\Http\Response
     */
    public function show(Order $order)
    {
        //
    }

   
    public function cancel(Request $request)
    {
        $request->validate([
            'uid'=>'required',
            'cloth_order_id'=>'required',
            
        ]);

        

        $user = UserLoginCred::where('uid', $request->uid)->first();
        if(!$user){
            return Response::json(['error'=>['UId is not valid'],422]);
        }

        $user = Order::where('order_id', $request->cloth_order_id)->first();

        if(!$user){
            return Response::json(['error'=>['cloth_order_id is not valid'],422]);
        }

        $user->status="Cancelled";

        $result = $user->save();
        if($result){
            return Response::json(["result"=>'Order Cancelled'],200);
        }else{
            return Response::json(["error"=>'Something Wrong!! Try Again'],500);
        }

        
    }

   
     
    public function update(Request $request)
    {

        $request->validate([
            'shid'=>'required',
            'cloth_order_id'=>'required',
            
            
        ]);

        $user = UserLoginCred::where('uid', $request->uid)->first();
        if(!$user){
            return Response::json(['error'=>['UId is not valid'],422]);
        }

        $user = Order::where('order_id', $request->cloth_order_id)->first();

        if(!$user){
            return Response::json(['error'=>['cloth_order_id is not valid'],422]);
        }

        $status = $user->status;

        if($status=='Placed'||$status=='placed'){


            $user->status=$request->option;

        }else if($status=='Accepted'||$status=='accepted'||$status=='Rejected'||$status=='rejected'){
            
            $user->status="picked";

        }
        else if($status=='Picked'||$status=='picked'){

            $user->status="completed";

        }else{
            return Response::json(["error"=>'Order Completed, We Cannot Update it or Order Does not Exist'],500);

        }

        $result = $user->save();
        if($result){
            return Response::json(["result"=>'Order Updated'],200);
        }else{
            return Response::json(["error"=>'Something Wrong!! Try Again'],500);
        }
        
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Order  $order
     * @return \Illuminate\Http\Response
     */
    public function destroy(Order $order)
    {
        //
    }


    public function userFetch($uid,$page){
        
        return Order::where('uid',$uid)->paginate($page);

    }

    public function shopFetch($shid,$type){
        if($type=="all"){
            return Order::where('shid',$shid)->paginate(20);
        }
        return Order::where('shid',$shid)->where('status',"like","%".$type."%")->paginate(20);
    }


    public function fetch($order_id){
        return Order::where('order_id',$order_id)->first();
    }

    public function stats($shid,$type){

        $data;

        if($type=="year"){

           $data= Order::where("shid",$shid)->where("status","completed")->where("updated_at",'>',Carbon::now()->subMonth(12)->toDateString())->get();


        }else if($type=="month"){
            $data= Order::where("shid",$shid)->where("status","completed")->where("updated_at",'>',Carbon::now()->subMonth()->toDateString())->get();

        }else if($type=="week"){
            $data= Order::where("shid",$shid)->where("status","completed")->where("updated_at",'>',Carbon::now()->subWeek()->toDateString())->get();

        }

        if($data==null){
            return Response::json(["order"=>"0","earning"=>"0"],200);
        }

        $earning=0;
        foreach($data as $order){
                $earning = $earning + (int)$order->total_cost/100;
        }
            $res = [
                "order"=>$data->count(),
                "earning"=>$earning
            ];

        return Response::json($res,200);

      

    }

    







    function sendNoti(){
        // Generated @ codebeautify.org

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, 'https://fcm.googleapis.com/v1/projects/myproject-b5ae1/messages:send');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, "{\n  \"message\": {\n    \"topic\" : \"foo-bar\",\n    \"notification\": {\n      \"body\": \"This is a Firebase Cloud Messaging Topic Message!\",\n      \"title\": \"FCM Message\"\n    }\n  }\n}");

        $headers = array();
        $headers[] = 'Authorization: Bearer ya29.ElqKBGN2Ri_Uz...HnS_uNreA';
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $result = curl_exec($ch);
        if (curl_errno($ch)) {
            echo 'Error:' . curl_error($ch);
        }
        curl_close($ch);

    }





public function invoice(Request $request){

    DB::beginTransaction();


    try{

        
        $key_id = "rzp_test_fsINoU7sl53QSj";
        $secret = "oQn36juzoWgmk3O70P69wDhY";
        $api = new Api($key_id, $secret);
        
        // $customer= $api->customer->create(array(
    //  'name' => $request->name,
    //  'email' => $request->email,
    //  'contact'=>$request->contact,
    //  ));

    
    
    $invoice = $api->invoice->create(array('type' => 'invoice','date' => Carbon::now(), 
    'customer'=> array(
        'name' => $request->name,
        //  'email' => $request->email,
        //  'contact'=>$request->contact,
    ),
    
    'line_items'=>array(array('name'=>'bathrobe','amount'=>'500000'))
    ))->issue();
    
    
    
    //$api->invoice->fetch($invoice->id)->edit(array('status'=>'paid'));
    
    
    
    $invoice = $api->invoice->fetch($invoice->id);
    $response = $invoice->short_url;
    
    }
    catch(Exception $e){

    }
    if($response!=null){
        return Response::json(['Invoice Url'=>$response],200);
        
    }
    
    return Response::json(['Invoice Url'=>"Error"],500);
    
    
    }

    


}
