<?php
// From Memcache to Google Storage
$memKey = $_POST['keyName'];
$m = new Memcache();
$imageMem = $m->get($memKey);
$gsPath = $_POST['gsPath'];
if($imageMem){
    file_put_contents($gsPath,$imageMem);
    header('HTTP', true, 200);
    syslog(LOG_INFO, "worker_success_".$_POST['keyName']);
    exit;
};
syslog(LOG_WARNING, "worker_not_exists_in_mem_".$_POST['keyName']);
header('HTTP', true, 200);
exit;