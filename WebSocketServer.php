<?php
/**
 * Copyright © 2011 by Jason Hendry 
 * 
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 * 
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 * 
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 * 
 * Based on http://code.google.com/p/phpwebsocket/
 * By georgenava and roy.laurent
 */

class WebSocketServer { 
  private $master;
  private $sockets;
  private $users;
  public $debug;
  
//  echo encodeFrame('Hello');
  
  function __constructor($bindip,$port) {
    $this->master = $this->WebSocket($bindip,$port);
    $this->sockets = array($this->master);
    $this->users = array();
    $this->debug = false;
  }
  
  function begin() {
    while(true){
      $changed = $this->sockets;
      try {
        socket_select($changed,$write=NULL,$except=NULL,NULL);
      }
      catch(exception $e) {
        echo "Socket Closed";
      }
      foreach($changed as $socket){
        if($socket==$this->master){
          $client=socket_accept($socket);
          if($client<0 || $client===false){ console("socket_accept() failed"); continue; }
          else{ $this->connect($client); }
        }
        else{
          $bytes = @socket_recv($socket,$buffer,2048,0);
          $this->console(bin2hex($buffer));
          if($bytes==0){ $this->disconnect($socket); }
          else{
            $user = $this->getuserbysocket($socket);
            if(!$user->handshake){ $this->dohandshake($user,$buffer); }
            else{ $this->process($user,$buffer); }
          }
        }
      }
    }
  }
  
  //---------------------------------------------------------------
  function process($user,$msg){
    $this->console($msg);
    if($user->version == 8) {
      $action = decodeFrame($msg);    
    } else {
      $action = unwrap($msg);
    }
    $this->say("< ".$action);
    switch($action){
      case "hello" : $response =  "hello human";                       break;
      case "hi"    : $response =  "zup human";                         break;
      case "name"  : $response =  "my name is Multivac, silly I know"; break;
      case "age"   : $response =  "I am older than time itself";       break;
      case "date"  : $response =  "today is ".date("Y.m.d");           break;
      case "time"  : $response =  "server time is ".date("H:i:s");     break;
      case "thanks": $response =  "you're welcome";                    break;
      case "bye"   : $response =  "bye";                               break;
      default      : $response =  $action." not understood";           break;
    }
    if($user->version == 8) {
      $response = $this->encodeFrame($response);
    }
    $this->send($user->socket,$response);
  }
  
  function send($client,$msg){
    $this->say("> ".$msg);
    socket_write($client,$msg,strlen($msg));
  }
  
  function WebSocket($address,$port){
    $this->master=socket_create(AF_INET, SOCK_STREAM, SOL_TCP)     or die("socket_create() failed");
    socket_set_option($this->master, SOL_SOCKET, SO_REUSEADDR, 1)  or die("socket_option() failed");
    socket_bind($this->master, $address, $port)                    or die("socket_bind() failed");
    socket_listen($this->master,20)                                or die("socket_listen() failed");
    $this->console("Server Started : ".date('Y-m-d H:i:s')."\n");
    $this->console("Master socket  : ".$this->master."\n");
    $this->console("Listening on   : ".$address." port ".$port."\n\n");
    return $this->master;
  }
  
  function connect($socket){
    $user = new WebSocketUser();
    $user->id = uniqid();
    $user->socket = $socket;
    array_push($this->users,$user);
    array_push($this->sockets,$socket);
    echo "Client Connected : ".$user->id."\n";
    console($socket." CONNECTED!");
  }
  
  function disconnect($socket){
    $found=null;
    $n=count($this->users);
    for($i=0;$i<$n;$i++){
      if($this->users[$i]->socket==$socket){ $found=$i; break; }
    }
    if(!is_null($found)){ array_splice($this->users,$found,1); }
    $index = array_search($socket,$this->sockets);
    socket_close($socket);
    $this->console($socket." DISCONNECTED!");
    if($index>=0){ array_splice($this->sockets,$index,1); }
  }
  
  function closeConnection($socket) {
    socket_close($socket);
    foreach($this->sockets as $i=>$s)
      if($socket == $s)
        array_splice($this->sockets,$i,1);
  }
  
  
  
  function dohandshake($user,$buffer){
    $this->console("\nRequesting handshake...");
    $this->console($buffer);
    list($resource,$host,$origin,$strkey1,$strkey2,$data,$version) = getheaders($buffer);
    console("Handshaking...");
  
    $pattern = '/[^\d]*/';
    $replacement = '';
    $numkey1 = preg_replace($pattern, $replacement, $strkey1);
    $numkey2 = preg_replace($pattern, $replacement, $strkey2);
  
    $pattern = '/[^ ]*/';
    $replacement = '';
    $spaces1 = strlen(preg_replace($pattern, $replacement, $strkey1));
    $spaces2 = strlen(preg_replace($pattern, $replacement, $strkey2));
  
    $user->version = $version;
  
    if($version >= 8) {
      if(!$strkey1) {
        $this->closeConnection($user->socket);
        $this->console('failed');
        return false;
      }
      $this->console(trim($strkey1));
      $accept = base64_encode(sha1(trim($strkey1)."258EAFA5-E914-47DA-95CA-C5AB0DC85B11",true));
      $upgrade  = "HTTP/1.1 101 Switching Protocols\r\n" .
                  "Upgrade: WebSocket\r\n" .
                  "Connection: Upgrade\r\n" .
                  "Sec-WebSocket-Accept: $accept\r\n".
                  "\r\n";
      socket_write($user->socket,$upgrade.chr(0),strlen($upgrade.chr(0)));
    } else {
      if ($spaces1 == 0 || $spaces2 == 0 || fmod($numkey1, $spaces1) != 0 || fmod($numkey2, $spaces2) != 0) {
        $this->closeConnection($user->socket);
        $this->console('failed');
        return false;
      }
      $ctx = hash_init('md5');
      hash_update($ctx, pack("N", $numkey1/$spaces1));
      hash_update($ctx, pack("N", $numkey2/$spaces2));
      hash_update($ctx, $data);
      $hash_data = hash_final($ctx,true);
  
      $upgrade  = "HTTP/1.1 101 WebSocket Protocol Handshake\r\n" .
                  "Upgrade: WebSocket\r\n" .
                  "Connection: Upgrade\r\n" .
                  "Sec-WebSocket-Origin: " . $origin . "\r\n" .
                  "Sec-WebSocket-Location: ws://" . $host . $resource . "\r\n" .
                  "\r\n" .
                  $hash_data;
    
      socket_write($user->socket,$upgrade.chr(0),strlen($upgrade.chr(0)));
      
    }
  
  
    
    $user->handshake=true;
    $this->console($upgrade);
    $this->console("Done handshaking...");
    return true;
  }
  
  function getheaders($req){
    $key1=$key2=$r=$h=$o=$ver=null;
    if(preg_match("/GET (.*) HTTP/"   ,$req,$match)){ $r=$match[1]; }
    if(preg_match("/Host: (.*)\r\n/"  ,$req,$match)){ $h=$match[1]; }
    if(preg_match("/Origin: (.*)\r\n/",$req,$match)){ $o=$match[1]; }
    if(preg_match("/Sec-WebSocket-Origin: (.*)\r\n/",$req,$match)){ $o=$match[1]; }
    if(preg_match("/Sec-WebSocket-Key2: (.*)\r\n/",$req,$match)){ $key2=$match[1]; }
    if(preg_match("/Sec-WebSocket-Key1: (.*)\r\n/",$req,$match)){ $key1=$match[1]; }
    if(preg_match("/Sec-WebSocket-Key: (.*)\r\n/",$req,$match)){ $key1=$match[1]; }
    if(preg_match("/Sec-WebSocket-Version: (.*)\r\n/",$req,$match)){ $ver=$match[1]; }
    if(preg_match("/\r\n(.*?)\$/",$req,$match)){ $data=$match[1]; }
    return array($r,$h,$o,$key1,$key2,$data,$ver);
  }
  
  function getuserbysocket($socket){
    $found=null;
    foreach($this->users as $user){
      if($user->socket==$socket){ $found=$user; break; }
    }
    return $found;
  }
  
  function     say($msg=""){ echo $msg."\n"; }
  function    wrap($msg=""){ return chr(0).$msg.chr(255); }
  function  unwrap($msg=""){ return substr($msg,1,strlen($msg)-2); }
  function console($msg=""){ if($this->debug){ echo $msg."\n"; } }
  
  function decodeFrame($msg) {
    $msg = unpack('C*',$msg);
  /*  
    $c = 0;
    foreach($msg as $m) {
      if($c%4==0)
        echo "\n";
        
      echo str_pad(decbin($m),8,'0',STR_PAD_LEFT)." ";
      $c++;
    }
    echo "\n\n";
    */
    $fin = $msg[1] & 128;
    $opt = $msg[1] & 15;
    $mask = $msg[2] & 128;
    $length = $msg[2] & 127;
    $pos = 3;
    if($length == 126) {
      $length = $msg[3]<<8+$msg[4];
      $pos = 5;
    } if($length == 127) {
      $length = ($msg[3]<<56)+($msg[4]<<48)+($msg[5]<<40)+($msg[6]<<32)+($msg[7]<<24)+($msg[8]<<16)+($msg[9]<<8)+($msg[10]);
      $pos = 11;
    }
    if($mask) {
      $maskKey = array($msg[$pos],$msg[$pos+1],$msg[$pos+2],$msg[$pos+3]);
    }
    $pos += 4;
    
    /*
    echo "Block 1(16bit):".decbin($msg[1])."\n";
    echo "FIN: ".($fin?'true':'false').'('.decbin($msg[1]>>8).")\n";
    echo "Opt: ".($opt)."\n";
    echo "Mask: ".($mask?'true':'false')."\n";
    echo "length: ".($length).'('.decbin($msg[1] & 129).")\n";
    //echo "maskKey: ".($maskKey).'('.decbin($maskKey).")\n";
    
    echo 'Payload Pos: '.$pos."\n";
  */
    $string = '';
    $keyPart = 0;
    for($i=0;$i<$length;$i++) {
      $maskedByte = $msg[$pos];
      if($maskKey)
        $unmaskedByte = $maskedByte ^ $maskKey[$keyPart%4];
      else
        $unmaskedByte = $maskedByte;
      
      //echo chr($unmaskedByte).' = '.decbin($unmaskedByte)." = ".decbin($maskedByte)." ^ ".decbin($maskKey[$keyPart%4])."\n";
      $string .= chr($unmaskedByte); 
  
      $keyPart++;
      $pos++;
    }
  
    return $string;
  }
  
  function encodeFrame($msg,$text=true,$mask=false) {
    $bytes = array();
    $bytes[0] = 0x80;
    if($text)
      $bytes[0] += 0x1; // OPT code text frame
    if(strlen($msg)<126) 
      $bytes[1] = strlen($msg);
    else if(strlen($msg)<0xFFFF) {
      $bytes[1] = 126;
      $bytes[]  = (strlen($msg) >> 8) & 0xFF;
      $bytes[]  = strlen($msg) & 0xFF;
    }
    else {
      $bytes[1] = 127;
      // TODO: Handle 64bit Int
    }
    if($mask) {
      // TODO: Handle Mask
    } else {
      for($i=0;$i<strlen($msg);$i++)
        $bytes[] = ord($msg[$i]);
    }
    $hexString = '';
    foreach($bytes as $i=>$b) {
      $hexString .= str_pad(dechex($b),2,'0',STR_PAD_LEFT);
      //echo $b."\n";
    }
  //  $hexString .= '0a';
    $bin = pack('H*',$hexString);
    
  /*  
    $msg = unpack('C*',$bin); 
    $c = 0;
    foreach($msg as $m) {
      if($c%4==0)
        echo "\n";
        
      echo str_pad(decbin($m),8,'0',STR_PAD_LEFT)." ";
      $c++;
    }
    echo "\n\n";
  */  
    
    return $bin;
  }
}

class WebSocketUser{
  var $id;
  var $socket;
  var $handshake;
}