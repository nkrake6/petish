<?php
/** Real ISO-BMFF fixtures, with independent structural/timing fault mutations. */
define('ABSPATH', __DIR__ . '/');
class WP_Error { public $code; public function __construct($code,$message=''){$this->code=$code;} }
require dirname(__DIR__).'/wordpress/wp-content/plugins/petish-passport/includes/class-petish-passport-video-inspector.php';
$fixtures=$argv[1]??'';$checks=array();$files=array();
function check($name,$ok){global $checks;if(!$ok)throw new RuntimeException($name);$checks[$name]=true;}
function number($s,$at){return unpack('N',substr($s,$at,4))[1];}
function replace32($s,$at,$value){return substr_replace($s,pack('N',$value),$at,4);}
function replace16($s,$at,$value){return substr_replace($s,pack('n',$value),$at,2);}
function box($type,$body=''){return pack('N',8+strlen($body)).$type.$body;}
function boxes_in($data,$start=0,$end=null){
 $end=$end??strlen($data);$result=array();
 while($start<$end){$size=number($data,$start);if($size<8||$start+$size>$end)throw new RuntimeException('fixture malformed');
  $type=substr($data,$start+4,4);$body=$start+8;$item=array('type'=>$type,'start'=>$start,'body'=>$body,'end'=>$start+$size,'size'=>$size);
  $result[]=$item;$skip=null;
  if(in_array($type,array('moov','trak','mdia','minf','stbl','dinf','edts','udta','ilst'),true))$skip=0;
  elseif(in_array($type,array('stsd','dref'),true))$skip=8;
  elseif('meta'===$type)$skip=4;
  elseif('avc1'===$type)$skip=78;
  elseif('mp4a'===$type)$skip=28;
  if(null!==$skip)$result=array_merge($result,boxes_in($data,$body+$skip,$start+$size));$start+=$size;
 }return $result;
}
function named($data,$name,$index=0){$boxes=array_values(array_filter(boxes_in($data),static function($box)use($name){return $box['type']===$name;}));if(!isset($boxes[$index]))throw new RuntimeException('fixture missing '.$name);return $boxes[$index];}
function mutation($name,$data,$accept=false){
 global $files;$file=tempnam(sys_get_temp_dir(),'petish-video-inspector-');$files[]=$file;file_put_contents($file,$data);
 $result=Petish_Passport_Video_Inspector::inspect($file);check($name,$accept?is_array($result):$result instanceof WP_Error);return $result;
}
function field($data,$type,$offset,$value,$index=0){$b=named($data,$type,$index);return replace32($data,$b['body']+$offset,$value);}
function add_moov($data,$child){
 $moov=named($data,'moov');$length=strlen($child);$positions=boxes_in($data);
 // Keep chunk references correct so successful metadata mutations exercise the intended parser path.
 foreach($positions as $entry)if('stco'===$entry['type']){for($i=0,$count=number($data,$entry['body']+4);$i<$count;$i++){
  $at=$entry['body']+8+4*$i;$offset=number($data,$at);if($offset>=$moov['end'])$data=replace32($data,$at,$offset+$length);
 }}
 $data=replace32($data,$moov['start'],$moov['size']+$length);return substr_replace($data,$child,$moov['end'],0);
}
try{
 $valid=file_get_contents($fixtures.'/valid.mp4');$audio=file_get_contents($fixtures.'/audio.mp4');$limit=file_get_contents($fixtures.'/limit.mp4');$long=file_get_contents($fixtures.'/long.mp4');$rectangle=file_get_contents($fixtures.'/rectangle.mp4');
 check('fixtures_present',is_string($valid)&&is_string($audio)&&is_string($limit)&&is_string($long)&&is_string($rectangle));
 $info=mutation('real_h264_silent_accepted',$valid,true);check('technical_properties_only',array_keys($info)===array('duration','width','height','coded_width','coded_height','video_codec','audio_codec','bytes'));
 check('silent_properties_correct',$info['duration']==1&&$info['width']===16&&$info['height']===16&&$info['video_codec']==='avc1'&&$info['audio_codec']===null&&$info['bytes']===strlen($valid));
 $info=mutation('real_h264_aac_under_limit_accepted',$audio,true);check('aac_properties_correct',$info['audio_codec']==='mp4a'&&$info['duration']>19&&$info['duration']<20);
 $info=mutation('exact_twenty_seconds_accepted',$limit,true);check('twenty_not_rounded',$info['duration']==20);
 mutation('twenty_point_four_nine_rejected',$long);
 $spoof=$long;
 foreach(boxes_in($spoof) as $b){
  if(in_array($b['type'],array('mvhd','mdhd'),true))$spoof=replace32($spoof,$b['body']+16,20*number($spoof,$b['body']+12));
  elseif('tkhd'===$b['type'])$spoof=replace32($spoof,$b['body']+20,20000);
  elseif('elst'===$b['type'])$spoof=replace32($spoof,$b['body']+8,20000);
 }
 mutation('spoofed_duration_headers_cannot_hide_long_sample_timing',$spoof);
 mutation('lying_movie_duration_rejected',field($valid,'mvhd',16,500));
 mutation('lying_track_duration_rejected',field($valid,'tkhd',20,500));
 mutation('lying_media_duration_rejected',field($valid,'mdhd',16,1));
 mutation('zero_timescale_rejected',field($valid,'mdhd',12,0));
 mutation('excessive_timescale_rejected',field($valid,'mvhd',12,10000001));
 mutation('zero_sample_duration_rejected',field($valid,'stts',12,0));
 mutation('excess_sample_time_runs_rejected',field($valid,'stts',4,5001));
 mutation('sample_timing_count_mismatch_rejected',field($valid,'stts',8,1));
 mutation('sample_count_limit_rejected',field($valid,'stsz',8,5001));
 mutation('zero_sample_size_rejected',field($valid,'stsz',12,0));
 mutation('oversized_sample_rejected',field($valid,'stsz',12,20971521));
 mutation('composition_offset_limit_rejected',field($valid,'ctts',12,4294967295));
 mutation('presentation_span_limit_rejected',field($valid,'ctts',12,20*12800));
 mutation('chunk_outside_file_rejected',field($valid,'stco',8,strlen($valid)+1));
 mutation('chunk_inside_metadata_rejected',field($valid,'stco',8,40));
 mutation('chunk_sample_coverage_rejected',field($valid,'stsc',12,1));
 mutation('chunk_description_reference_rejected',field($valid,'stsc',16,2));
 mutation('chunk_first_index_rejected',field($valid,'stsc',8,2));
 mutation('sync_samples_out_of_range_rejected',field($valid,'stss',8,5000));
 mutation('external_data_reference_rejected',field($valid,'url ',0,0));
 mutation('multiple_data_references_rejected',field($valid,'dref',4,2));
 $b=named($valid,'avc1');mutation('unsupported_video_codec_rejected',substr_replace($valid,'hvc1',$b['start']+4,4));
 mutation('encrypted_video_entry_rejected',substr_replace($valid,'encv',$b['start']+4,4));
 $b=named($audio,'mp4a');mutation('encrypted_audio_entry_rejected',substr_replace($audio,'enca',$b['start']+4,4));
 $b=named($valid,'hdlr');mutation('nonvideo_handler_rejected',substr_replace($valid,'text',$b['body']+8,4));
 $b=named($valid,'avcC');mutation('unsupported_h264_profile_rejected',substr_replace($valid,chr(244),$b['body']+1,1));
 mutation('invalid_avcc_length_size_rejected',substr_replace($valid,chr(254),$b['body']+4,1));
 mutation('missing_sps_rejected',substr_replace($valid,chr(224),$b['body']+5,1));
 mutation('invalid_sps_nal_rejected',substr_replace($valid,chr(104),$b['body']+8,1));
 $b=named($audio,'esds');$needle="\x05\x80\x80\x80\x05\x12\x08";$at=strpos($audio,$needle,$b['body']);check('aac_fixture_config_located',false!==$at);
 mutation('unsupported_aac_object_type_rejected',substr_replace($audio,chr(0x1a),$at+5,1));
 mutation('aac_sample_rate_mismatch_rejected',field($audio,'mp4a',24,48000*65536));
 $b=named($audio,'btrt');mutation('bitrate_box_wrong_payload_length_rejected',replace32($audio,$b['start'],19));
 mutation('audio_video_chunk_overlap_rejected',field($audio,'stco',8,number($audio,named($audio,'stco')['body']+8),1));
 mutation('fragmented_mp4_rejected',$valid.box('moof'));
 mutation('uuid_layout_rejected',$valid.box('uuid',str_repeat("\0",16)));
 mutation('partial_trailing_box_rejected',$valid.'x');
 mutation('truncated_media_rejected',substr($valid,0,-1));
 mutation('box_outside_parent_rejected',replace32($valid,0,strlen($valid)+100));
 mutation('size_zero_box_rejected',replace32($valid,0,0));
 mutation('invalid_brand_rejected',substr_replace($valid,'zzzz',8,4));
 mutation('empty_mdat_rejected',$valid.box('mdat'));
 mutation('movie_duplicate_rejected',$valid.substr($valid,named($valid,'moov')['start'],named($valid,'moov')['size']));
 $track=named($valid,'trak');mutation('duplicate_video_track_rejected',add_moov($valid,substr($valid,$track['start'],$track['size'])));
 $b=named($valid,'avc1');$lie=replace16($valid,$b['body']+24,32);$lie=field($lie,'tkhd',76,32*65536);
 mutation('sps_dimensions_must_match_sample_headers',$lie);
 foreach(array(90=>array(0,65536,-65536,0),270=>array(0,-65536,65536,0)) as $degrees=>$matrix){
  $rotated=$rectangle;$b=named($rotated,'tkhd');foreach(array(0,1,3,4) as $i=>$index)$rotated=replace32($rotated,$b['body']+40+4*$index,$matrix[$i]);
  $info=mutation('rotation_'.$degrees.'_accepted',$rotated,true);check('rotation_'.$degrees.'_display_and_coded_dimensions',$info['width']===240&&$info['height']===320&&$info['coded_width']===320&&$info['coded_height']===240);
 }
 mutation('matrix_scaling_rejected',field($valid,'tkhd',40,2*65536));
 mutation('edit_rate_rejected',field($valid,'elst',16,2*65536));
 mutation('negative_edit_start_rejected',field($valid,'elst',12,4294967295));
 mutation('multiple_edits_rejected',field($valid,'elst',4,2));
 mutation('valid_optional_padding_accepted',$valid.box('free',str_repeat("\0",64)),true);
 $meta=box('udta',box('meta',"\0\0\0\0".box('ilst',box("\xa9too",box('data',"\0\0\0\1\0\0\0\0Generated fixture")))));
 mutation('bounded_ordinary_metadata_accepted',add_moov($valid,$meta),true);
 $numeric=box('meta',"\0\0\0\0".box('keys',"\0\0\0\0".pack('N',1).pack('N',12).'mdta'.'test').box('ilst',box("\0\0\0\1",box('data',"\0\0\0\1\0\0\0\0Value"))));
 mutation('bounded_numeric_metadata_accepted',add_moov($valid,$numeric),true);
 mutation('metadata_compressed_movie_rejected',add_moov($valid,box('udta',box('cmov',box('cmvd',pack('N',1073741824).'compressed')))));
 mutation('metadata_compressed_data_rejected',add_moov($valid,box('udta',box('cmvd',pack('N',1073741824).'compressed'))));
 mutation('metadata_sample_table_rejected',add_moov($valid,box('udta',box('stts',"\0\0\0\0".pack('N',4294967295)))));
 mutation('metadata_keys_count_bounded',add_moov($valid,box('meta',"\0\0\0\0".box('keys',"\0\0\0\0".pack('N',4294967295)))));
 mutation('metadata_keys_length_bounded',add_moov($valid,box('meta',"\0\0\0\0".box('keys',"\0\0\0\0".pack('N',1).pack('N',4294967295).'mdta'))));
 mutation('numeric_metadata_cannot_nest_containers',add_moov($valid,box('meta',"\0\0\0\0".box('ilst',box("\0\0\0\1",box('cmov'))))));
 mutation('metadata_unrecognized_container_rejected',add_moov($valid,box('udta',box('NCDT',box('udta')))));
 mutation('moov_payload_bounded',add_moov($valid,box('free',str_repeat("\0",1048576))));
 mutation('box_count_bounded',$valid.str_repeat(box('free'),513));
 mutation('exact_size_limit_accepted',$valid.box('free',str_repeat("\0",20971520-strlen($valid)-8)),true);
 mutation('size_over_limit_rejected',$valid.box('free',str_repeat("\0",20971521-strlen($valid)-8)));
 $source=$files[0];$link=$source.'-symlink';$files[]=$link;symlink($source,$link);check('symlink_rejected',Petish_Passport_Video_Inspector::inspect($link) instanceof WP_Error);
 $hard=$source.'-hardlink';$files[]=$hard;link($source,$hard);check('hardlink_rejected',Petish_Passport_Video_Inspector::inspect($source) instanceof WP_Error);
 check('missing_file_rejected',Petish_Passport_Video_Inspector::inspect('/does-not-exist/petish.mp4') instanceof WP_Error);
 check('nonstring_path_rejected',Petish_Passport_Video_Inspector::inspect(array()) instanceof WP_Error);
 echo json_encode(array('status'=>'success','checks'=>$checks)),"\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}finally{foreach($files as $file)if(is_file($file)||is_link($file))unlink($file);}
