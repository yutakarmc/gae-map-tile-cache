<?php
// This is an experimantal code. So you should fix this code to make sure it's secure and/or suitable and so on before production
use google\appengine\api\taskqueue\PushTask;
use google\appengine\api\taskqueue\PushQueue;

//your origin WMS Server 
$originUrl = "replace your wms server url like http://foo.com/wms/";

//your Google App Engine Project Name, for Google Storage URI
$projectName = "replace this area  your Google App Engine Project Name like foo-bar-cache";

$tile = new TileXYZ($_SERVER['REQUEST_URI']);
$tileImageKey = $tile->Layer."/".$tile->Z."/".$tile->X."/".$tile->Filename;

header('Content-type: '.$tile->ImageFormat());
// add ETag to enable cache systems for Google CDN and end users' browser
header('Pragma: cache');
header('Cache-Control: public, max-age=31536000');

$last_modified = gmdate( "D, d M Y H:i:s T", time() );
$etag = md5( $last_modified.$tileImageKey);
header( "Last-Modified: {$last_modified}" );
header( "Etag: {$etag}" );

// Try to get from Memcache
$m = new Memcache();
$tileImage = $m->get($tileImageKey);

if($tileImage){
    header('X-Map-Cache: Memcache');
    echo $tileImage;
    syslog(LOG_DEBUG, "memcache");
    exit;
}

// Try to get from Google Storage
$storagePath = "gs://staging.".$projectName.".appspot.com/".$tileImageKey;
//$gsPath = "gs://".$projectName.".appspot.com/".$layer."/".$zoom."/".$x."/".$filename;

$tileImage = file_get_contents($storagePath);
if($tileImage){
    header('X-Map-Cache: Google Storage');
    echo($tileImage);
    $m->set($tileImageKey,$tileImage);
    syslog(LOG_DEBUG, "google storage");
    exit;
}

// Try to get from origin wms server
header('X-Map-Cache: Got from origin wms server');
$tileImage = file_get_contents($originUrl.'/?'. $tile->WMSQuery());

if(! $tileImage){
    header('HTTP', true, 500);
    syslog(LOG_NOTICE, "error during fetching");
    exit;
}

// Set the image in Memcache
echo($tileImage);
$m->set($tileImageKey,$tileImage);
syslog(LOG_INFO, "origin wms server");

//Schedule to copy the tile image from Memcache to Google Storage. Also take a look at store.php
$task = new PushTask(
    '/worker',
    ['keyName' => $tileImageKey, 'gsPath' => $storagePath]);
$task_name = $task->add();
exit;

//zxy → 4326bbox
class TileXYZ{
    function __construct($url){
        //assume https://<your-project-name>.appspot.com/<layer-name>/<z>/<x>/<y>.<file-extension>
        $urlPath = parse_url($url, PHP_URL_PATH);
        $tileParameters = explode('/',$urlPath);
        if(count($tileParameters)< 5){exit;}
        $this->Layer = urldecode($tileParameters[1]);
        $this->Z = $tileParameters[2];
        $this->X = $tileParameters[3];
        $this->Filename = $tileParameters[4];
        
        $arr = explode('.', $this->Filename);
        $this->Y = $arr[0];
        $this->FileExtension = $arr[1];
    }

    public $Layer;
    public $X;
    public $Y;
    public $Z;
    public $Filename;
    public $FileExtension;

    function ImageFormat(){
        return "image/".$this->FileExtension;
    }

    function boundingBox($z, $x, $y){
        $zPow = pow(2, $z); 
        $ulwlng = $x / $zPow * 360.0 - 180.0;
        $ulwlat = rad2deg(atan(sinh(pi() * (1-2 * ($y+1)/ $zPow))));
        $lrwlng =($x + 1 ) / $zPow * 360.0 - 180.0;
        $lrwlat = rad2deg(atan(sinh(pi() * (1-2 * ($y) / $zPow))));
        return $ulwlng. ',' . $ulwlat . ',' . $lrwlng . ',' . $lrwlat;
    }

    function Bbox(){
        return $this->boundingBox($this->Z, $this->X, $this->Y);
    }

    function WMSQuery(){
        $tileSize = 256.0;
        $query = array(
            'SERVICE'=>'WMS',
            'REQUEST'=>'GetMap',
            'VERSION'=>'1.1.1',
            'LAYERS'=>$this->Layer,
            'Styles'=>'',
            'SRS'=>'EPSG:4326',
            'BBOX'=>$this->Bbox(),
            'width'=>$tileSize,
            'height'=>$tileSize,
            'format'=>$this->FileExtension,
            'TRANSPARENT'=>'TRUE'
            );
        return http_build_query($query);
    }
}

