<?php

/*
 * PHP server library for EnvayaSMS
 *
 * For example usage see example/www/index.php
 */

class EnvayaSMS
{
    const ACTION_INCOMING = 'incoming';
    const ACTION_OUTGOING = 'outgoing';
    const ACTION_SEND_STATUS = 'send_status';
    const ACTION_DEVICE_STATUS = 'device_status';
    const ACTION_TEST = 'test';

    const STATUS_QUEUED = 'queued';
    const STATUS_FAILED = 'failed';
    const STATUS_SENT = 'sent';
    const STATUS_CANCELLED = 'cancelled';
    
    const DEVICE_STATUS_POWER_CONNECTED = "power_connected";
    const DEVICE_STATUS_POWER_DISCONNECTED = "power_disconnected";
    const DEVICE_STATUS_BATTERY_LOW = "battery_low";
    const DEVICE_STATUS_BATTERY_OKAY = "battery_okay";
    const DEVICE_STATUS_SEND_LIMIT_EXCEEDED = "send_limit_exceeded";
    
    const MESSAGE_TYPE_SMS = 'sms';
    const MESSAGE_TYPE_MMS = 'mms';    
    const MESSAGE_TYPE_CALL = 'call';
    
    const NETWORK_MOBILE = "MOBILE";    
    const NETWORK_WIFI = "WIFI";
    
    static function escape($val)
    {
        return htmlspecialchars($val, ENT_COMPAT, 'UTF-8');
    }    
    
    private static $request;
    
    static function get_request()
    {
        if (!isset(static::$request))
        {
            $version = @$_POST['version'];     

            // If API version changes, could return
            // different EnvayaSMS_Request instance
            // to support multiple phone versions

            static::$request = new EnvayaSMS_Request();
        }
        return static::$request;
    }             

    static function get_error_xml($message)
    {
        ob_start();
        echo "<?xml version='1.0' encoding='UTF-8'?>\n";
        echo "<response>";
        echo "<error>";
        echo EnvayaSMS::escape($message);
        echo "</error>";
        echo "</response>";
        return ob_get_clean();
    }
    
    static function get_success_xml()
    {
        ob_start();
        echo "<?xml version='1.0' encoding='UTF-8'?>\n";
        echo "<response></response>";
        return ob_get_clean();    
    }       
}

class EnvayaSMS_Request
{   
    private $request_action;
    
    public $version;
    public $phone_number;
    public $log;
    
    public $version_name;
    public $sdk_int;
    public $manufacturer;
    public $model;
    public $network;

    function __construct()
    {
        $this->version = $_POST['version'];
        $this->phone_number = $_POST['phone_number'];
        $this->log = $_POST['log'];
        $this->network = @$_POST['network'];
        
        if (preg_match('#/(?P<version_name>[\w\.\-]+) \(Android; SDK (?P<sdk_int>\d+); (?P<manufacturer>[^;]*); (?P<model>[^\)]*)\)#', 
            @$_SERVER['HTTP_USER_AGENT'], $matches))
        {
            $this->version_name = $matches['version_name'];            
            $this->sdk_int = $matches['sdk_int'];
            $this->manufacturer = $matches['manufacturer'];
            $this->model = $matches['model'];
        }
    }
    
    function get_action()
    {
        if (!$this->request_action)
        {
            $this->request_action = $this->_get_action();
        }
        return $this->request_action;
    }
    
    private function _get_action()
    {
        switch (@$_POST['action'])
        {
            case EnvayaSMS::ACTION_INCOMING:
                return new EnvayaSMS_Action_Incoming($this);
            case EnvayaSMS::ACTION_OUTGOING:
                return new EnvayaSMS_Action_Outgoing($this);                
            case EnvayaSMS::ACTION_SEND_STATUS:
                return new EnvayaSMS_Action_SendStatus($this);
            case EnvayaSMS::ACTION_TEST:
                return new EnvayaSMS_Action_Test($this);
            case EnvayaSMS::ACTION_DEVICE_STATUS:
                return new EnvayaSMS_Action_DeviceStatus($this);                
            default:
                return new EnvayaSMS_Action($this);
        }
    }            
    
    function is_validated($correct_password)
    {
        $signature = @$_SERVER['HTTP_X_REQUEST_SIGNATURE'];        
        if (!$signature)
        {
            return false;
        }
        
        $is_secure = (!empty($_SERVER['HTTPS']) AND filter_var($_SERVER['HTTPS'], FILTER_VALIDATE_BOOLEAN));
        $protocol = $is_secure ? 'https' : 'http';
        $full_url = $protocol . "://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];    
        
        $correct_signature = $this->compute_signature($full_url, $_POST, $correct_password);           
        
        //error_log("Correct signature: '$correct_signature'");
        
        return $signature === $correct_signature;
    }

    function compute_signature($url, $data, $password)
    {
        ksort($data);
        
        $input = $url;
        foreach($data as $key => $value)
            $input .= ",$key=$value";

        $input .= ",$password";
        
        //error_log("Signed data: '$input'");
        
        return base64_encode(sha1($input, true));            
    }    
    
    static function get_messages_xml($messages)
    {
        ob_start();
        echo "<?xml version='1.0' encoding='UTF-8'?>\n";
        echo "<response>";
        echo "<messages>";
        foreach ($messages as $message)
        {       
            $type = isset($message->type) ? $message->type : EnvayaSMS::MESSAGE_TYPE_SMS;
            $id = isset($message->id) ? " id=\"".EnvayaSMS::escape($message->id)."\"" : "";
            $to = isset($message->to) ? " to=\"".EnvayaSMS::escape($message->to)."\"" : "";        
            $priority = isset($message->priority) ? " priority=\"".$message->priority."\"" : "";        
            echo "<$type$id$to$priority>".EnvayaSMS::escape($message->message)."</$type>";
        }
        echo "</messages>";        
        echo "</response>";
        return ob_get_clean();    
    }           
}

class EnvayaSMS_OutgoingMessage
{
    public $id;             // ID generated by server
    public $to;             // destination phone number
    public $message;        // content of SMS message
    public $priority;       // integer priority, higher numbers will be sent first
    public $type;            // EnvayaSMS::MESSAGE_TYPE_* value (default sms)
}

class EnvayaSMS_Action
{
    public $type;    
    public $request;
    
    function __construct($request)
    {
        $this->request = $request;
    }
}

class EnvayaSMS_MMS_Part
{
    public $form_name;  // name of form field with MMS part content
    public $cid;        // MMS Content-ID
    public $type;       // Content type
    public $filename;   // Original filename of MMS part on sender phone
    public $tmp_name;   // Temporary file where MMS part content is stored
    public $size;       // Content length
    public $error;      // see http://www.php.net/manual/en/features.file-upload.errors.php

    function __construct($args)
    {
        $this->form_name = $args['name'];
        $this->cid = $args['cid'];
        $this->type = $args['type'];
        $this->filename = $args['filename'];
        
        $file = $_FILES[$this->form_name];
        
        $this->tmp_name = $file['tmp_name'];
        $this->size = $file['size'];
        $this->error = $file['error'];
    }
}

class EnvayaSMS_Action_Incoming extends EnvayaSMS_Action
{    
    public $from;           // Sender phone number
    public $message;        // The message body of the SMS, or the content of the text/plain part of the MMS.
    public $message_type;   // EnvayaSMS::MESSAGE_TYPE_MMS or EnvayaSMS::MESSAGE_TYPE_SMS
    public $mms_parts;      // array of EnvayaSMS_MMS_Part instances
    public $timestamp;      // timestamp of incoming message (added in version 12)
    public $age;            // delay in ms between time when message originally received and when forwarded to server (added in version 18)

    function __construct($request)
    {
        parent::__construct($request);
        $this->type = EnvayaSMS::ACTION_INCOMING;
        $this->from = $_POST['from'];
        $this->message = @$_POST['message'];
        $this->message_type = $_POST['message_type'];
        $this->timestamp = @$_POST['timestamp'];
        $this->age = @$_POST['age'];
        
        if ($this->message_type == EnvayaSMS::MESSAGE_TYPE_MMS)
        {
            $this->mms_parts = array();
            foreach (json_decode($_POST['mms_parts'], true) as $mms_part)
            {
                $this->mms_parts[] = new EnvayaSMS_MMS_Part($mms_part);
            }
        }               
    }    
    
    function get_response_xml($messages)
    {
        return $this->request->get_messages_xml($messages);
    }    
}

class EnvayaSMS_Action_Outgoing extends EnvayaSMS_Action
{    
    function __construct($request)
    {
        parent::__construct($request);
        $this->type = EnvayaSMS::ACTION_OUTGOING;
    }    
    
    function get_response_xml($messages)
    {
        return $this->request->get_messages_xml($messages);
    }    
}

class EnvayaSMS_Action_Test extends EnvayaSMS_Action
{    
    function __construct($request)
    {
        parent::__construct($request);
        $this->type = EnvayaSMS::ACTION_TEST;
    }
}

class EnvayaSMS_Action_SendStatus extends EnvayaSMS_Action
{    
    public $status;     // EnvayaSMS::STATUS_* values
    public $id;         // server ID previously used in EnvayaSMS_OutgoingMessage
    public $error;      // textual description of error (if applicable)
    
    function __construct($request)
    {
        parent::__construct($request);   
        $this->type = EnvayaSMS::ACTION_SEND_STATUS;        
        $this->status = $_POST['status'];
        $this->id = $_POST['id'];
        $this->error = $_POST['error'];
    } 
}

class EnvayaSMS_Action_DeviceStatus extends EnvayaSMS_Action
{    
    public $status;     // EnvayaSMS::DEVICE_STATUS_* values
    
    function __construct($request)
    {
        parent::__construct($request);   
        $this->type = EnvayaSMS::ACTION_DEVICE_STATUS;        
        $this->status = $_POST['status'];
    } 
}
