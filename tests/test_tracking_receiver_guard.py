"""Behavioral checks for the isolated AST receiver guard, without WordPress writes."""
import json
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
GUARD = ROOT / "scripts/petish-tracking-receiver-guard.php"
PHP = shutil.which("php") or "/workspace/.petish-dev/bin/php"

FIXTURE = r'''
define('DB_NAME','fixture_staging');
$GLOBALS['safe_context']=true;$GLOBALS['admin']=true;$GLOBALS['hooks']=[];
$GLOBALS['parcels']=[];$GLOBALS['messages']=[];$GLOBALS['events']=[];
$GLOBALS['creates']=0;$GLOBALS['reads']=0;$GLOBALS['remap']=7;
function petish_tracking_fail(){throw new RuntimeException('fixture rejected');}
function petish_tracking_context($context){if(!$GLOBALS['safe_context'])petish_tracking_fail();return $context;}
function petish_tracking_scoped_order($context,$id){
 if($id!==7)petish_tracking_fail();return $GLOBALS['order'];
}
function add_filter($hook,$callback,$priority,$arguments){$GLOBALS['hooks'][$hook]=[$callback,$priority,$arguments];}
function remove_filter($hook,$callback,$priority){unset($GLOBALS['hooks'][$hook]);}
function current_user_can($cap){return $GLOBALS['admin'];}
function is_wp_error($value){return $value instanceof WP_Error;}
class WP_Error{
 function __construct(public $code,public $message='',public $data=[]){}
 function get_error_data(){return $this->data;}
}
class WP_REST_Response{
 function __construct(public $data,public $status=200){}
 function get_status(){return $this->status;}
 function get_data(){return $this->data;}
}
class WP_REST_Request implements ArrayAccess{
 private array $body=[];private array $url=[];private array $extra=[];
 function __construct(private string $method,private string $route){}
 function get_method(){return $this->method;}
 function get_route(){return $this->route;}
 function set_body_params($params){$this->body=$params;}
 function set_url_params($params){$this->url=$params;}
 function set_param($key,$value){$this->extra[$key]=$value;}
 public function offsetExists(mixed $key):bool{return isset($this->extra[$key])||isset($this->body[$key])||isset($this->url[$key]);}
 public function offsetGet(mixed $key):mixed{return $this->extra[$key]??$this->body[$key]??$this->url[$key]??null;}
 public function offsetSet(mixed $key,mixed $value):void{$this->extra[$key]=$value;}
 public function offsetUnset(mixed $key):void{unset($this->extra[$key],$this->body[$key],$this->url[$key]);}
}
class FixtureLine{
 public int $quantity=2;
 function get_id(){return 11;}function get_quantity(){return $this->quantity;}
}
class FixtureOrder{
 public string $status='processing';public $line;
 function __construct(){$this->line=new FixtureLine();}
 function get_items(){return [11=>$this->line];}function get_status(){return $this->status;}
}
$GLOBALS['order']=new FixtureOrder();
class FixtureDatabase{
 public bool $held=false;public bool $busy=false;
 function prepare($query,$name){return str_replace('%s',"'".$name."'",$query);}
 function get_var($query){
  if(str_starts_with($query,'SELECT GET_LOCK(')){
   $GLOBALS['events'][]='lock';if($this->busy)return '0';$this->held=true;return '1';
  }
  if(str_starts_with($query,'SELECT RELEASE_LOCK(')){
   $GLOBALS['events'][]='release';if(!$this->held)return '0';$this->held=false;return '1';
  }
  if(str_starts_with($query,'SELECT IS_FREE_LOCK('))return !$this->held&&!$this->busy?'1':'0';
  throw new RuntimeException('unexpected SQL');
 }
}
$wpdb=new FixtureDatabase();
class WC_Advanced_Shipment_Tracking_Admin{
 static function get_instance(){return new self();}
 function get_provider_slug_from_name($name){return $name==='Petish staging carrier'?'petish-rehearsal':$name;}
}
class WC_Advanced_Shipment_Tracking_Actions{
 static function get_instance(){return new self();}
 function get_formated_order_id($id){return $GLOBALS['remap'];}
 function get_tracking_items($id){$GLOBALS['events'][]='read';return $GLOBALS['parcels'];}
}
class WC_Advanced_Shipment_Tracking_REST_API_Controller{
 function create_item_permissions_check($request){
  return $GLOBALS['admin']?true:new WP_Error('native_permission','Denied',['status'=>403]);
 }
 function create_item($request){
  $GLOBALS['creates']++;
  if(isset($GLOBALS['reentry'])){
   $callback=$GLOBALS['reentry'];unset($GLOBALS['reentry']);$callback();
  }
  if($GLOBALS['native_throw']??false)throw new RuntimeException('native callback failure');
  $item=['tracking_id'=>md5($request['tracking_number'].'-'.$GLOBALS['creates']),
   'tracking_provider'=>'petish-rehearsal','tracking_number'=>$request['tracking_number'],
   'custom_tracking_link'=>$request['custom_tracking_link'],'date_shipped'=>strtotime($request['date_shipped'].' UTC'),
   'status_shipped'=>(string)$request['status_shipped']];
  $GLOBALS['parcels'][]=$item;
  if((int)$request['status_shipped']===2){
   $GLOBALS['order']->status='partial-shipped';$GLOBALS['messages'][]='partial';
  }
  if((int)$request['status_shipped']===1){
   $GLOBALS['order']->status='completed';$GLOBALS['messages'][]='completed';
  }
  return new WP_REST_Response($item,201);
 }
 function get_item($request){
  $GLOBALS['reads']++;
  if((int)$request['order_id']!==7)return new WP_Error('wrong_order','',['status'=>404]);
  foreach($GLOBALS['parcels']as$item)if($item['tracking_id']===$request['id'])return new WP_REST_Response($item,200);
  return new WP_Error('missing_item','',['status'=>404]);
 }
}
$controller=new WC_Advanced_Shipment_Tracking_REST_API_Controller();
$handler=['callback'=>[$controller,'create_item'],'permission_callback'=>[$controller,'create_item_permissions_check']];
function request($number='PARCEL-ONE',$status=2,$overrides=[],$route='/wc-ast/v3/orders/7/shipment-trackings'){
 $request=new WP_REST_Request('POST',$route);
 $request->set_url_params(['order_id'=>7]);
 $request->set_body_params(array_merge(['tracking_provider'=>'Petish staging carrier',
  'custom_tracking_provider'=>'Petish staging carrier','tracking_number'=>$number,
  'custom_tracking_link'=>'https://tracking.petish.invalid/parcel/'.$number,
  'date_shipped'=>'2026-10-20','status_shipped'=>$status,'replace_tracking'=>0],$overrides));
 return $request;
}
function dispatch($guard,$request){global $handler;return $guard->dispatch(null,$request,'native-route-pattern',$handler);}
function status($value){return is_wp_error($value)?$value->get_error_data()['status']:$value->get_status();}
'''


@unittest.skipUnless(Path(PHP).is_file(), "PHP required")
class TrackingReceiverGuardTests(unittest.TestCase):
    def run_php(self, body):
        with tempfile.TemporaryDirectory(prefix="petish-tracking-guard-") as directory:
            script = Path(directory) / "fixture.php"
            script.write_text("<?php\n" + FIXTURE + "\nrequire " + repr(str(GUARD)) + ";\n" + body)
            result = subprocess.run([PHP, str(script)], capture_output=True, timeout=20)
            self.assertEqual(result.returncode, 0, "Guard fixture failed; private diagnostics suppressed")
            self.assertFalse(result.stderr, "Guard fixture emitted diagnostics")
            values = json.loads(result.stdout)
            self.assertTrue(all(values), values)

    def test_inclusion_registers_nothing_and_unsafe_context_cannot_install(self):
        self.run_php(r'''
$out=[count($GLOBALS['hooks'])===0];$GLOBALS['safe_context']=false;
try{Petish_Tracking_Receiver_Rehearsal_Guard::install([],7);$out[]=false;}catch(RuntimeException $e){$out[]=true;}
$out[]=count($GLOBALS['hooks'])===0;
$GLOBALS['safe_context']=true;$guard=Petish_Tracking_Receiver_Rehearsal_Guard::install([],7);
$out[]=isset($GLOBALS['hooks']['rest_dispatch_request'])&&count($GLOBALS['hooks'])===1;
$guard->uninstall();$out[]=count($GLOBALS['hooks'])===0;echo json_encode($out);
''')

    def test_native_permissions_and_owned_route_cannot_be_bypassed(self):
        self.run_php(r'''
$guard=Petish_Tracking_Receiver_Rehearsal_Guard::install([],7);$GLOBALS['admin']=false;
$denied=dispatch($guard,request());$out=[$denied instanceof WP_Error&&$denied->code==='native_permission'];
$GLOBALS['admin']=true;
foreach(['/wc-ast/v3/orders/8/shipment-trackings','/wc-ast/v3/orders/007/shipment-trackings',
 '/wc-ast/v3/orders/7/shipment-trackings/']as$route)$out[]=status(dispatch($guard,request('PARCEL-ONE',2,[],$route)))===403;
foreach([8,[], '007']as$id)$out[]=status(dispatch($guard,request('PARCEL-ONE',2,['order_id'=>$id])))===403;
$GLOBALS['remap']=8;$out[]=status(dispatch($guard,request()))===403;$GLOBALS['remap']=7;
$prior=new WP_Error('previous','',['status'=>401]);
$out[]=$guard->dispatch($prior,request(),'',$handler)===$prior;
$out[]=$GLOBALS['creates']===0&&$GLOBALS['events']===[];echo json_encode($out);
''')

    def test_exact_replay_returns_existing_native_resource_without_email_or_append(self):
        self.run_php(r'''
$guard=Petish_Tracking_Receiver_Rehearsal_Guard::install([],7);$first=dispatch($guard,request());
$before=$GLOBALS['parcels'];$replay=dispatch($guard,request('PARCEL-ONE',2,['id'=>'attacker-id','context'=>'view']));
$out=[status($first)===201,status($replay)===200,$replay->get_data()===$first->get_data(),
 $GLOBALS['parcels']===$before,$GLOBALS['creates']===1,$GLOBALS['reads']===1,
 $GLOBALS['messages']===['partial'],$GLOBALS['events']===['lock','read','release','lock','read','release'],
 $guard->locks_released()];echo json_encode($out);
''')

    def test_blank_malformed_replacement_and_carrier_input_never_reaches_native_writer(self):
        self.run_php(r'''
$guard=Petish_Tracking_Receiver_Rehearsal_Guard::install([],7);$out=[];
foreach(['',' ','A B',"A\nB",str_repeat('A',129),[],false]as$number)
 $out[]=status(dispatch($guard,request('PARCEL-ONE',2,['tracking_number'=>$number])))===400;
foreach([1,'1','01',true,1.0,[],false]as$value)
 $out[]=status(dispatch($guard,request('PARCEL-ONE',2,['replace_tracking'=>$value])))===409;
foreach([['custom_tracking_link'=>'https://untrusted.invalid/parcel/PARCEL-ONE'],
 ['tracking_provider'=>'Unknown'],['tracking_id'=>'existing'],['date_shipped'=>'2026-02-31'],
 ['date_shipped'=>'next week'],['status_shipped'=>3]]as$override)
 $out[]=status(dispatch($guard,request('PARCEL-ONE',2,$override)))>=400;
$out[]=$GLOBALS['creates']===0&&$GLOBALS['parcels']===[]&&$GLOBALS['messages']===[];
$out[]=$guard->locks_released();echo json_encode($out);
''')

    def test_conflicting_replay_and_destructive_replacement_preserve_existing_parcels(self):
        self.run_php(r'''
$guard=Petish_Tracking_Receiver_Rehearsal_Guard::install([],7);dispatch($guard,request());$before=$GLOBALS['parcels'];
$out=[status(dispatch($guard,request('PARCEL-ONE',1)))===409,
 status(dispatch($guard,request('PARCEL-ONE',2,['date_shipped'=>'2026-10-19'])))===409,
 status(dispatch($guard,request('PARCEL-TWO',2,['replace_tracking'=>1])))===409,
 $GLOBALS['parcels']===$before,$GLOBALS['creates']===1,$GLOBALS['messages']===['partial'],
 $guard->locks_released()];echo json_encode($out);
''')

    def test_completion_requires_trusted_item_quantity_coverage_and_replay_stays_quiet(self):
        self.run_php(r'''
$guard=Petish_Tracking_Receiver_Rehearsal_Guard::install([],7);dispatch($guard,request());$out=[];
$out[]=status(dispatch($guard,request('PARCEL-TWO',1,['confirmed'=>true,'allocations'=>['PARCEL-TWO'=>[11=>2]]])))===409;
$out[]=$GLOBALS['order']->status==='partial-shipped'&&count($GLOBALS['parcels'])===1;
foreach([['PARCEL-ONE'=>[11=>1]],['PARCEL-ONE'=>[99=>2]],['PARCEL-ONE'=>[11=>3]]]as$plan){
 try{$guard->set_confirmed_fixture_allocations($plan);$out[]=false;}catch(RuntimeException $e){$out[]=true;}
}
$guard->set_confirmed_fixture_allocations(['PARCEL-ONE'=>[11=>2]]);
$out[]=status(dispatch($guard,request('PARCEL-TWO',1)))===409;
$guard->set_confirmed_fixture_allocations(['PARCEL-ONE'=>[11=>1],'PARCEL-TWO'=>[11=>1]]);
$out[]=status(dispatch($guard,request('PARCEL-TWO',1)))===201;
$out[]=$GLOBALS['order']->status==='completed'&&count($GLOBALS['parcels'])===2;
$before=$GLOBALS['parcels'];$out[]=status(dispatch($guard,request('PARCEL-TWO',1)))===200;
$out[]=$GLOBALS['parcels']===$before&&$GLOBALS['messages']===['partial','completed'];
$out[]=status(dispatch($guard,request('PARCEL-THREE',2)))===409;
$out[]=$GLOBALS['messages']===['partial','completed'];echo json_encode($out);
''')

    def test_coverage_is_rechecked_against_current_line_quantities(self):
        self.run_php(r'''
$guard=Petish_Tracking_Receiver_Rehearsal_Guard::install([],7);dispatch($guard,request());
$guard->set_confirmed_fixture_allocations(['PARCEL-ONE'=>[11=>1],'PARCEL-TWO'=>[11=>1]]);
$GLOBALS['order']->line->quantity=3;
echo json_encode([status(dispatch($guard,request('PARCEL-TWO',1)))===409,
 count($GLOBALS['parcels'])===1,$GLOBALS['messages']===['partial'],$guard->locks_released()]);
''')

    def test_lock_contention_and_reentry_do_not_execute_another_native_write(self):
        self.run_php(r'''
$guard=Petish_Tracking_Receiver_Rehearsal_Guard::install([],7);$wpdb->busy=true;
$out=[status(dispatch($guard,request()))===409,$GLOBALS['creates']===0];$wpdb->busy=false;
$GLOBALS['reentry']=static function()use($guard,&$out){
 $out[]=status(dispatch($guard,request('PARCEL-TWO',2)))===409;
};
$out[]=status(dispatch($guard,request()))===201;$out[]=$GLOBALS['creates']===1;
$out[]=$GLOBALS['messages']===['partial'];$out[]=$guard->locks_released();echo json_encode($out);
''')

    def test_callback_failure_releases_the_database_lock(self):
        self.run_php(r'''
$guard=Petish_Tracking_Receiver_Rehearsal_Guard::install([],7);$GLOBALS['native_throw']=true;
$out=[];try{dispatch($guard,request());$out[]=false;}catch(RuntimeException $e){$out[]=true;}
$out[]=$guard->locks_released();$guard->uninstall();$out[]=count($GLOBALS['hooks'])===0;
echo json_encode($out);
''')

    def test_ambiguous_existing_duplicate_records_are_not_repaired_or_notified(self):
        self.run_php(r'''
$guard=Petish_Tracking_Receiver_Rehearsal_Guard::install([],7);dispatch($guard,request());
$GLOBALS['parcels'][]=$GLOBALS['parcels'][0];$before=$GLOBALS['parcels'];
echo json_encode([status(dispatch($guard,request()))===409,$GLOBALS['parcels']===$before,
 $GLOBALS['creates']===1,$GLOBALS['messages']===['partial'],$guard->locks_released()]);
''')


if __name__ == "__main__":
    unittest.main()
