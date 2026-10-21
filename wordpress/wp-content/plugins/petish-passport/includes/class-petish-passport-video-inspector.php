<?php
/** Bounded structural validation of progressive H.264/AAC MP4, without decoding or metadata stripping. */
defined('ABSPATH') || exit;
final class Petish_Passport_Video_Inspector {
 const MAX_SECONDS=20;
 const MAX_BYTES=20971520;
 const MAX_SAMPLES=5000;
 const MAX_BOXES=512;
 const MAX_DEPTH=10;
 const MAX_MOOV_BYTES=1048576;
 const MAX_TIMESCALE=10000000; // Keeps all cross-timescale products below 2^53.
 private $file;
 private $bytes;
 private $box_count=0;
 private $mdat=array();
 private $used=array();

 /** @return array|WP_Error Technical properties only; never returns embedded tags or filenames. */
 public static function inspect($path){
  $handle=false;
  try{
   if(!is_string($path)||''===$path||is_link($path)||!is_file($path))throw new RuntimeException();
   $before=lstat($path);
   if(!$before||1!==$before['nlink']||$before['size']<32||$before['size']>self::MAX_BYTES)throw new RuntimeException();
   $handle=fopen($path,'rb');if(!$handle)throw new RuntimeException();
   $opened=fstat($handle);
   if(!$opened||$opened['ino']!==$before['ino']||$opened['dev']!==$before['dev']||$opened['size']!==$before['size'])throw new RuntimeException();
   $reader=new self();$reader->file=$handle;$reader->bytes=$opened['size'];
   $result=$reader->movie();$after=fstat($handle);
   if(!$after||$after['size']!==$opened['size']||$after['mtime']!==$opened['mtime']||$after['ctime']!==$opened['ctime'])throw new RuntimeException();
   return $result;
  }catch(Throwable $error){
   return new WP_Error('petish_video_format','Choose a regular MP4 video with H.264 picture and optional AAC sound, no longer than 20 seconds and no larger than 20 MB.');
  }finally{if(is_resource($handle))fclose($handle);}
 }
 private function reject(){throw new RuntimeException();}
 private function read($at,$length){
  if(!is_int($at)||!is_int($length)||$at<0||$length<0||$length>self::MAX_MOOV_BYTES||$at>$this->bytes-$length)$this->reject();
  if(0===$length)return '';
  if(0!==fseek($this->file,$at))$this->reject();
  $value=fread($this->file,$length);if(!is_string($value)||strlen($value)!==$length)$this->reject();return $value;
 }
 private function u16($s,$at){if($at<0||strlen($s)<$at+2)$this->reject();return unpack('n',substr($s,$at,2))[1];}
 private function u32($s,$at){if($at<0||strlen($s)<$at+4)$this->reject();return unpack('N',substr($s,$at,4))[1];}
 private function i32($s,$at){$n=$this->u32($s,$at);return $n>=2147483648?$n-4294967296:$n;}
 private function u64($s,$at){
  $high=$this->u32($s,$at);$low=$this->u32($s,$at+4);
  // All accepted durations, offsets and table sizes are safely below 2^53.
  if($high>2097151)$this->reject();return $high*4294967296+$low;
 }
 private function body($box){return $this->read($box['body'],$box['end']-$box['body']);}
 private function boxes($start,$end,$depth){
  if($depth>self::MAX_DEPTH||$start<0||$end>$this->bytes||$start>$end)$this->reject();
  $items=array();
  while($start<$end){
   if(++$this->box_count>self::MAX_BOXES||$end-$start<8)$this->reject();
   $header=$this->read($start,8);$size=$this->u32($header,0);$head=8;$type=substr($header,4,4);
   if(1===$size){if($end-$start<16)$this->reject();$size=$this->u64($this->read($start+8,8),0);$head=16;}
   // Size-zero/end-of-file and ambiguous partial boxes are outside this progressive subset.
   if($size<$head||$size>$end-$start)$this->reject();
   $items[]=array('type'=>$type,'start'=>$start,'body'=>$start+$head,'end'=>$start+$size,'size'=>$size,'depth'=>$depth);
   $start+=$size;
  }
  return $items;
 }
 private function children($box,$skip=0){return $this->boxes($box['body']+$skip,$box['end'],$box['depth']+1);}
 private function only($items,$types){foreach($items as $box)if(!in_array($box['type'],$types,true))$this->reject();}
 private function one($items,$type,$required=true){
  $found=null;foreach($items as $box)if($box['type']===$type){if(null!==$found)$this->reject();$found=$box;}
  if($required&&null===$found)$this->reject();return $found;
 }
 private function full($data,$versions=array(0)){
  if(strlen($data)<4||!in_array(ord($data[0]),$versions,true))$this->reject();
  return array(ord($data[0]),$this->u32($data,0)&16777215);
 }
 private function metadata($box){
  // Explicitly bound the metadata tree before getID3, including numeric key containers.
  // In particular, never pass compressed movie headers or arbitrary vendor containers onward.
  if($box['size']>self::MAX_MOOV_BYTES)$this->reject();$type=$box['type'];
  $text=array("\xa9nam","\xa9ART","\xa9alb","\xa9too","\xa9day","\xa9cmt","\xa9xyz",'cprt','desc');
  if('udta'===$type){
   $items=$this->children($box);$this->only($items,array_merge(array('meta','free'),$text));
   foreach($items as $item)if('meta'===$item['type'])$this->metadata($item);
  }elseif('meta'===$type){
   if("\0\0\0\0"!==$this->read($box['body'],4))$this->reject();
   $items=$this->children($box,4);$this->only($items,array('hdlr','keys','ilst','free'));
   foreach(array('hdlr','keys','ilst') as $name)$this->one($items,$name,false);
   foreach($items as $item){
    if('ilst'===$item['type'])$this->metadata($item);
    elseif('hdlr'===$item['type']){if($item['size']<32||$item['size']>1024)$this->reject();}
    elseif('keys'===$item['type']){
     $data=$this->body($item);list($version,$flags)=$this->full($data);$count=$this->u32($data,4);$at=8;
     if($flags||$count>128)$this->reject();
     for($i=0;$i<$count;$i++){$size=$this->u32($data,$at);if($size<8||$size>4096||$size>strlen($data)-$at)$this->reject();$at+=$size;}
     if($at!==strlen($data))$this->reject();
    }
   }
  }elseif('ilst'===$type){
   foreach($this->children($box) as $item){
    $numeric=substr($item['type'],0,3)==="\0\0\0"&&ord($item['type'][3])>0;
    if(!$numeric&&!in_array($item['type'],array_merge($text,array('----','covr')),true))$this->reject();
    $values=$this->children($item);$this->only($values,'----'===$item['type']?array('data','mean','name'):array('data'));
    if(!$values)$this->reject();foreach($values as $value)if($value['size']<16)$this->reject();
   }
  }else $this->reject();
 }
 private function movie(){
  $top=$this->boxes(0,$this->bytes,0);
  $this->only($top,array('ftyp','moov','mdat','free','skip','wide'));
  $ftyp=$this->one($top,'ftyp');$moov=$this->one($top,'moov');
  if($ftyp['start']!==0||$ftyp['size']>256||$moov['size']>self::MAX_MOOV_BYTES)$this->reject();
  $brands=$this->body($ftyp);
  if(strlen($brands)<8||strlen($brands)%4||!in_array(substr($brands,0,4),array('isom','iso2','iso4','iso5','iso6','iso8','mp41','mp42','avc1','M4V ','MSNV'),true))$this->reject();
  foreach($top as $box){
   if('mdat'===$box['type']){if($box['end']===$box['body'])$this->reject();$this->mdat[]=array($box['body'],$box['end']);}
  }
  if(!$this->mdat||count($this->mdat)>8)$this->reject();
  $children=$this->children($moov);$this->only($children,array('mvhd','trak','udta','meta','free','skip'));
  $mvhd=$this->body($this->one($children,'mvhd'));list($version,$flags)=$this->full($mvhd,array(0,1));
  if($flags||strlen($mvhd)!==($version?112:100))$this->reject();
  $scale=$this->u32($mvhd,$version?20:12);$duration=$version?$this->u64($mvhd,24):$this->u32($mvhd,16);
  if($scale<1||$scale>self::MAX_TIMESCALE||$duration<1||$duration>self::MAX_SECONDS*$scale||$this->u32($mvhd,$version?32:20)!==65536)$this->reject();
  $tracks=array();$ids=array();$maximum=0;
  foreach($children as $child){
   if(in_array($child['type'],array('meta','udta'),true))$this->metadata($child);
   if('trak'!==$child['type'])continue;
   if(count($tracks)>=2)$this->reject();
   $track=$this->track($child,$scale);
   if(isset($tracks[$track['kind']])||isset($ids[$track['id']]))$this->reject();
   $tracks[$track['kind']]=$track;$ids[$track['id']]=true;$maximum=max($maximum,$track['duration']);
  }
  if(!isset($tracks['vide'])||abs($maximum-$duration)>1)$this->reject();
  usort($this->used,static function($a,$b){return $a[0]<=>$b[0];});$last=-1;
  foreach($this->used as $range){if($range[0]<$last)$this->reject();$last=$range[1];}
  return array('duration'=>$duration/$scale,'width'=>$tracks['vide']['width'],'height'=>$tracks['vide']['height'],
   'coded_width'=>$tracks['vide']['coded_width'],'coded_height'=>$tracks['vide']['coded_height'],
   'video_codec'=>'avc1','audio_codec'=>isset($tracks['soun'])?'mp4a':null,'bytes'=>$this->bytes);
 }
 private function track($box,$movie_scale){
  $parts=$this->children($box);$this->only($parts,array('tkhd','edts','mdia','udta','meta','free'));
  foreach($parts as $part)if(in_array($part['type'],array('meta','udta'),true))$this->metadata($part);
  $tkhd=$this->body($this->one($parts,'tkhd'));list($version,$flags)=$this->full($tkhd,array(0,1));
  if(strlen($tkhd)!==($version?96:84)||($flags&3)!==3||($flags&~7))$this->reject();
  $id=$this->u32($tkhd,$version?20:12);$duration=$version?$this->u64($tkhd,28):$this->u32($tkhd,20);
  if(!$id||!$duration||$duration>self::MAX_SECONDS*$movie_scale)$this->reject();
  $matrix_offset=$version?52:40;$matrix=array();for($i=0;$i<9;$i++)$matrix[]=$this->i32($tkhd,$matrix_offset+4*$i);
  // Permit ordinary quarter-turn rotation, without arbitrary scaling or perspective.
  if($matrix[2]!==0||$matrix[5]!==0||$matrix[8]!==1073741824)$this->reject();
  $rotation=array($matrix[0],$matrix[1],$matrix[3],$matrix[4]);
  if(!in_array($rotation,array(array(65536,0,0,65536),array(0,65536,-65536,0),array(-65536,0,0,-65536),array(0,-65536,65536,0)),true))$this->reject();
  $display_w=$this->u32($tkhd,$matrix_offset+36);$display_h=$this->u32($tkhd,$matrix_offset+40);
  $mdia=$this->children($this->one($parts,'mdia'));$this->only($mdia,array('mdhd','hdlr','minf','elng'));
  $mdhd=$this->body($this->one($mdia,'mdhd'));list($version,$flags)=$this->full($mdhd,array(0,1));
  if($flags||strlen($mdhd)!==($version?36:24))$this->reject();
  $scale=$this->u32($mdhd,$version?20:12);$media_duration=$version?$this->u64($mdhd,24):$this->u32($mdhd,16);
  if(!$scale||$scale>self::MAX_TIMESCALE||!$media_duration||$media_duration>self::MAX_SECONDS*$scale)$this->reject();
  $hdlr=$this->body($this->one($mdia,'hdlr'));list($version,$flags)=$this->full($hdlr);
  if($flags||strlen($hdlr)<24||strlen($hdlr)>1024)$this->reject();$kind=substr($hdlr,8,4);
  if(!in_array($kind,array('vide','soun'),true))$this->reject();
  $minf=$this->children($this->one($mdia,'minf'));$this->only($minf,array('vmhd','smhd','dinf','stbl','free'));
  $media_header=$this->body($this->one($minf,'vide'===$kind?'vmhd':'smhd'));
  list($v,$f)=$this->full($media_header);
  if(('vide'===$kind&&(strlen($media_header)!==12||$f!==1||$this->one($minf,'smhd',false)))
   ||('soun'===$kind&&(strlen($media_header)!==8||$f!==0||$this->one($minf,'vmhd',false))))$this->reject();
  $this->data_reference($this->one($minf,'dinf'));
  $tables=$this->children($this->one($minf,'stbl'));
  $this->only($tables,array('stsd','stts','ctts','stsc','stsz','stco','co64','stss','sgpd','sbgp','free'));
  $entry=$this->description($this->one($tables,'stsd'),$kind);
  if('vide'===$kind){
   if($display_w!==$entry['width']*65536||$display_h!==$entry['height']*65536)$this->reject();
   $width=$matrix[0]===0?$entry['height']:$entry['width'];$height=$matrix[0]===0?$entry['width']:$entry['height'];
   if($width>3840||$height>2160)$this->reject();
  }else{if($display_w||$display_h)$this->reject();$width=0;$height=0;}
  $sizes=$this->sample_sizes($this->one($tables,'stsz'));
  $deltas=$this->time_table($this->one($tables,'stts'),count($sizes),false,$scale);
  $ctts=$this->one($tables,'ctts',false);$offsets=$ctts?$this->time_table($ctts,count($sizes),true,$scale):array_fill(0,count($sizes),0);
  $dts=0;$start=null;$end=0;
  foreach($deltas as $i=>$delta){$pts=$dts+$offsets[$i];$start=null===$start?$pts:min($start,$pts);$end=max($end,$pts+$delta);$dts+=$delta;}
  if($dts!==$media_duration||$dts>self::MAX_SECONDS*$scale||$end-$start>self::MAX_SECONDS*$scale)$this->reject();
  $edit=$this->one($parts,'edts',false);
  if($edit){
   $edits=$this->children($edit);$this->only($edits,array('elst'));$elst=$this->body($this->one($edits,'elst'));
   list($version,$flags)=$this->full($elst,array(0,1));
   if($flags||strlen($elst)!==($version?28:20)||$this->u32($elst,4)!==1)$this->reject();
   $segment=$version?$this->u64($elst,8):$this->u32($elst,8);
   $media_time=$version?$this->u64($elst,16):$this->i32($elst,12);$rate=$this->u32($elst,$version?24:16);
   if($segment!==$duration||$media_time<0||$media_time<$start||$media_time>=$end||$rate!==65536)$this->reject();
   // One movie timescale tick permits only integer conversion rounding, never extra duration.
   if($duration*$scale>($end-$media_time)*$movie_scale+$scale)$this->reject();
  }else{
   if($start<0||$end>self::MAX_SECONDS*$scale||abs($duration*$scale-$end*$movie_scale)>$scale)$this->reject();
  }
  $stss=$this->one($tables,'stss',false);if($stss)$this->sync_samples($stss,count($sizes),$kind);
  $sgpd=$this->one($tables,'sgpd',false);$sbgp=$this->one($tables,'sbgp',false);
  if((bool)$sgpd!==(bool)$sbgp)$this->reject();if($sgpd)$this->roll_group($sgpd,$sbgp,count($sizes),$kind);
  $this->chunks($tables,$sizes);
  $coded_width=$entry['width']??0;$coded_height=$entry['height']??0;
  return compact('id','kind','duration','width','height','coded_width','coded_height');
 }
 private function data_reference($dinf){
  $items=$this->children($dinf);$this->only($items,array('dref'));$dref=$this->one($items,'dref');$head=$this->read($dref['body'],8);
  list($v,$flags)=$this->full($head);if($flags||$this->u32($head,4)!==1)$this->reject();
  $refs=$this->children($dref,8);if(count($refs)!==1||$refs[0]['type']!=='url '||$this->body($refs[0])!=="\0\0\0\1")$this->reject();
 }
 private function description($stsd,$kind){
  $head=$this->read($stsd['body'],8);list($v,$flags)=$this->full($head);
  if($flags||$this->u32($head,4)!==1)$this->reject();$entries=$this->children($stsd,8);
  if(count($entries)!==1)$this->reject();$entry=$entries[0];$data=$this->body($entry);
  if(substr($data,0,6)!==str_repeat("\0",6)||$this->u16($data,6)!==1)$this->reject();
  if('vide'===$kind){
   if($entry['type']!=='avc1'||strlen($data)<78)$this->reject();
   $width=$this->u16($data,24);$height=$this->u16($data,26);
   if(!$width||!$height||$width>3840||$height>2160||$width*$height>8294400)$this->reject();
   $configs=$this->children($entry,78);$this->only($configs,array('avcC','pasp','btrt','colr','fiel'));
   $this->bitrate_box($configs);
   $this->avcc($this->body($this->one($configs,'avcC')),$width,$height);
   $pasp=$this->one($configs,'pasp',false);
   if($pasp){$ratio=$this->body($pasp);if(strlen($ratio)!==8||$this->u32($ratio,0)!==$this->u32($ratio,4)||!$this->u32($ratio,0))$this->reject();}
   return compact('width','height');
  }
  if($entry['type']!=='mp4a'||strlen($data)<28||$this->u16($data,8)!==0)$this->reject();
  $channels=$this->u16($data,16);$rate=$this->u32($data,24);
  if(!in_array($channels,array(1,2),true)||$this->u16($data,18)!==16||($rate&65535)||!($rate>>16)||($rate>>16)>96000)$this->reject();
  $configs=$this->children($entry,28);$this->only($configs,array('esds','btrt'));
  $this->bitrate_box($configs);
  $this->esds($this->body($this->one($configs,'esds')),$channels,$rate>>16);return array();
 }
 private function bitrate_box($configs){$box=$this->one($configs,'btrt',false);if($box&&strlen($this->body($box))!==12)$this->reject();}
 private function sample_sizes($box){
  $data=$this->body($box);list($v,$flags)=$this->full($data);$fixed=$this->u32($data,4);$count=$this->u32($data,8);
  if($flags||$count<1||$count>self::MAX_SAMPLES||$fixed>self::MAX_BYTES||strlen($data)!==12+($fixed?0:4*$count))$this->reject();
  $sizes=array();for($i=0;$i<$count;$i++){$size=$fixed?:$this->u32($data,12+4*$i);if(!$size||$size>self::MAX_BYTES)$this->reject();$sizes[]=$size;}return $sizes;
 }
 private function time_table($box,$samples,$composition,$scale){
  $data=$this->body($box);list($version,$flags)=$this->full($data,$composition?array(0,1):array(0));$count=$this->u32($data,4);
  if($flags||$count<1||$count>self::MAX_SAMPLES||strlen($data)!==8+8*$count)$this->reject();$values=array();$total=0;
  for($i=0;$i<$count;$i++){
   $n=$this->u32($data,8+8*$i);$value=$version?$this->i32($data,12+8*$i):$this->u32($data,12+8*$i);
   if($n<1||$n>$samples-count($values)||abs($value)>self::MAX_SECONDS*$scale||(!$composition&&$value<1))$this->reject();
   if(!$composition){$total+=$n*$value;if($total>self::MAX_SECONDS*$scale)$this->reject();}
   for($j=0;$j<$n;$j++)$values[]=$value;
  }
  if(count($values)!==$samples)$this->reject();return $values;
 }
 private function sync_samples($box,$samples,$kind){
  if('vide'!==$kind)$this->reject();$data=$this->body($box);list($v,$flags)=$this->full($data);$count=$this->u32($data,4);
  if($flags||$count<1||$count>$samples||strlen($data)!==8+4*$count||$this->u32($data,8)!==1)$this->reject();$last=0;
  for($i=0;$i<$count;$i++){$sample=$this->u32($data,8+4*$i);if($sample<=$last||$sample>$samples)$this->reject();$last=$sample;}
 }
 private function roll_group($sgpd,$sbgp,$samples,$kind){
  if('soun'!==$kind)$this->reject();$desc=$this->body($sgpd);list($v,$flags)=$this->full($desc,array(1));
  if($flags||strlen($desc)!==18||substr($desc,4,4)!=='roll'||$this->u32($desc,8)!==2||$this->u32($desc,12)!==1)$this->reject();
  $distance=$this->u16($desc,16);if($distance<65520)$this->reject(); // Bounded AAC decoder priming only.
  $groups=$this->body($sbgp);list($v,$flags)=$this->full($groups);$count=$this->u32($groups,8);
  if($flags||substr($groups,4,4)!=='roll'||$count<1||$count>$samples||strlen($groups)!==12+8*$count)$this->reject();$total=0;
  for($i=0;$i<$count;$i++){$n=$this->u32($groups,12+8*$i);$index=$this->u32($groups,16+8*$i);if(!$n||$index>1||$n>$samples-$total)$this->reject();$total+=$n;}
  if($total!==$samples)$this->reject();
 }
 private function chunks($tables,$sizes){
  $stco=$this->one($tables,'stco',false);$co64=$this->one($tables,'co64',false);
  if((bool)$stco===(bool)$co64)$this->reject();$data=$this->body($stco?:$co64);list($v,$flags)=$this->full($data);$count=$this->u32($data,4);$step=$co64?8:4;
  if($flags||$count<1||$count>count($sizes)||strlen($data)!==8+$step*$count)$this->reject();$offsets=array();
  for($i=0;$i<$count;$i++)$offsets[]=$co64?$this->u64($data,8+$step*$i):$this->u32($data,8+$step*$i);
  $map=$this->body($this->one($tables,'stsc'));list($v,$flags)=$this->full($map);$entries=$this->u32($map,4);
  if($flags||$entries<1||$entries>$count||strlen($map)!==8+12*$entries)$this->reject();$runs=array();$previous=0;
  for($i=0;$i<$entries;$i++){
   $first=$this->u32($map,8+12*$i);$n=$this->u32($map,12+12*$i);$description=$this->u32($map,16+12*$i);
   if($first<=$previous||$first>$count||(!$i&&$first!==1)||!$n||$n>count($sizes)||$description!==1)$this->reject();
   $runs[]=array($first,$n);$previous=$first;
  }
  $sample=0;$run=0;
  foreach($offsets as $i=>$offset){
   if(isset($runs[$run+1])&&$runs[$run+1][0]===$i+1)$run++;
   $n=$runs[$run][1];if($n>count($sizes)-$sample)$this->reject();$bytes=0;
   for($j=0;$j<$n;$j++)$bytes+=$sizes[$sample++];
   if($bytes>$this->bytes||$offset>$this->bytes-$bytes)$this->reject();$end=$offset+$bytes;$inside=false;
   foreach($this->mdat as $range)if($offset>=$range[0]&&$end<=$range[1]){$inside=true;break;}
   if(!$inside)$this->reject();$this->used[]=array($offset,$end);
  }
  if($sample!==count($sizes))$this->reject();
 }
 private function bits($data,&$position,$count){
  if($count<0||$count>32||$position<0||$position+$count>strlen($data)*8)$this->reject();$value=0;
  for($i=0;$i<$count;$i++,$position++)$value=$value*2+((ord($data[intdiv($position,8)])>>(7-$position%8))&1);
  return $value;
 }
 private function ue($data,&$position){
  $zeros=0;while(0===$this->bits($data,$position,1)){if(++$zeros>20)$this->reject();}
  return (1<<$zeros)-1+$this->bits($data,$position,$zeros);
 }
 private function se($data,&$position){$value=$this->ue($data,$position);return $value%2?intdiv($value+1,2):-intdiv($value,2);}
 private function sps($nal,$width,$height,$profile,$level){
  if(strlen($nal)<5||strlen($nal)>4096||(ord($nal[0])&159)!==7||ord($nal[1])!==$profile||ord($nal[3])!==$level)$this->reject();
  $rbsp='';$zeros=0;
  for($i=1;$i<strlen($nal);$i++){
   $byte=ord($nal[$i]);if($zeros>=2&&$byte===3){if(!isset($nal[$i+1])||ord($nal[$i+1])>3)$this->reject();$zeros=0;continue;}
   $rbsp.=$nal[$i];$zeros=$byte===0?$zeros+1:0;
  }
  $position=24;$id=$this->ue($rbsp,$position);if($id>31)$this->reject();
  if($profile===100){
   if($this->ue($rbsp,$position)!==1||$this->ue($rbsp,$position)!==0||$this->ue($rbsp,$position)!==0)$this->reject();
   $this->bits($rbsp,$position,1);if($this->bits($rbsp,$position,1))$this->reject(); // Custom scaling lists are outside this subset.
  }
  if($this->ue($rbsp,$position)>12)$this->reject();$order=$this->ue($rbsp,$position);
  if($order===0){if($this->ue($rbsp,$position)>12)$this->reject();}
  elseif($order===1){$this->bits($rbsp,$position,1);$this->se($rbsp,$position);$this->se($rbsp,$position);$cycles=$this->ue($rbsp,$position);if($cycles>256)$this->reject();for($i=0;$i<$cycles;$i++)$this->se($rbsp,$position);}
  elseif($order!==2)$this->reject();
  if($this->ue($rbsp,$position)>16)$this->reject();$this->bits($rbsp,$position,1);
  $mbw=$this->ue($rbsp,$position)+1;$mbh=$this->ue($rbsp,$position)+1;
  if($mbw>256||$mbh>256||$this->bits($rbsp,$position,1)!==1)$this->reject();$this->bits($rbsp,$position,1);
  $left=$right=$top=$bottom=0;
  if($this->bits($rbsp,$position,1)){$left=$this->ue($rbsp,$position);$right=$this->ue($rbsp,$position);$top=$this->ue($rbsp,$position);$bottom=$this->ue($rbsp,$position);}
  if($mbw*16-2*($left+$right)!==$width||$mbh*16-2*($top+$bottom)!==$height)$this->reject();return $id;
 }
 private function avcc($data,$width,$height){
  if(strlen($data)<7||strlen($data)>65536||ord($data[0])!==1||!in_array(ord($data[1]),array(66,77,100),true)
   ||ord($data[3])<10||ord($data[3])>52||ord($data[4])!==255||(ord($data[5])&224)!==224)$this->reject();
  $profile=ord($data[1]);$level=ord($data[3]);$count=ord($data[5])&31;if($count<1||$count>4)$this->reject();$at=6;$ids=array();
  for($i=0;$i<$count;$i++){$size=$this->u16($data,$at);$at+=2;if($size<5||$size>4096||$size>strlen($data)-$at)$this->reject();$id=$this->sps(substr($data,$at,$size),$width,$height,$profile,$level);if(isset($ids[$id]))$this->reject();$ids[$id]=true;$at+=$size;}
  if($at>=strlen($data))$this->reject();$count=ord($data[$at++]);if($count<1||$count>8)$this->reject();
  for($i=0;$i<$count;$i++){$size=$this->u16($data,$at);$at+=2;if($size<2||$size>4096||$size>strlen($data)-$at)$this->reject();$nal=substr($data,$at,$size);if((ord($nal[0])&159)!==8)$this->reject();$pos=8;if($this->ue($nal,$pos)>255||!isset($ids[$this->ue($nal,$pos)]))$this->reject();$at+=$size;}
  if($at<strlen($data)){
   if($profile!==100||strlen($data)-$at!==4||substr($data,$at)!=="\xfd\xf8\xf8\0")$this->reject();
  }
 }
 private function descriptor($data,&$at,$tag){
  if($at>=strlen($data)||ord($data[$at++])!==$tag)$this->reject();$size=0;
  for($i=0;$i<4;$i++){if($at>=strlen($data))$this->reject();$byte=ord($data[$at++]);$size=$size*128+($byte&127);if(!($byte&128))break;if($i===3)$this->reject();}
  if($size>strlen($data)-$at)$this->reject();$result=substr($data,$at,$size);$at+=$size;return $result;
 }
 private function esds($data,$channels,$rate){
  if(strlen($data)>4096)$this->reject();list($v,$flags)=$this->full($data);if($flags)$this->reject();$at=4;$es=$this->descriptor($data,$at,3);
  if($at!==strlen($data)||strlen($es)<3||(ord($es[2])&224))$this->reject();$at=3;$config=$this->descriptor($es,$at,4);
  if(strlen($config)<13||ord($config[0])!==64||ord($config[1])!==21)$this->reject();$decoder=13;$specific=$this->descriptor($config,$decoder,5);
  if($decoder!==strlen($config)||strlen($specific)<2||strlen($specific)>8)$this->reject();
  $position=0;if($this->bits($specific,$position,5)!==2)$this->reject();$index=$this->bits($specific,$position,4);
  $rates=array(96000,88200,64000,48000,44100,32000,24000,22050,16000,12000,11025,8000,7350);
  $frequency=$index===15?$this->bits($specific,$position,24):($rates[$index]??0);
  if($frequency!==$rate||$this->bits($specific,$position,4)!==$channels||$this->bits($specific,$position,3)!==0)$this->reject();
  if(strlen($specific)*8-$position>=16){
   if($this->bits($specific,$position,11)!==695||$this->bits($specific,$position,5)!==5||$this->bits($specific,$position,1)!==0)$this->reject();
  }
  while($position<strlen($specific)*8)if($this->bits($specific,$position,1)!==0)$this->reject();
  $sl=$this->descriptor($es,$at,6);if($sl!=="\2"||$at!==strlen($es))$this->reject();
 }
}
