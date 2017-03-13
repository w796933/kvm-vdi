<?php
/*
KVM-VDI
Tadas Ustinavičius
2017-03-13
Vilnius, Lithuania.
*/
//############################################################################################
function memcachedReadConfig(){
    include (dirname(__FILE__) . '/../../../functions/config.php');
    $memcache = new Memcache;
    $memcache->connect($memcached_address, $memcached_port) or die ("Could not connect to memcached");
    $config=array();
    $config['token']=memcache_get($memcache, 'token');
    $config['tokenExpire']=memcache_get($memcache, 'tokenExpire');
    $config['computeURL']=memcache_get($memcache, 'computeURL');
    return $config;
}
//############################################################################################
function OpenStackConnect(){
    include (dirname(__FILE__) . '/../../../functions/config.php');
    $memcache = new Memcache;
    $memcache->connect($memcached_address, $memcached_port) or die ("Could not connect to memcached");
    $tokenExpire=memcache_get($memcache, 'tokenExpire');
    $currDateTime = new DateTime('now');
    $expireDateTime = new DateTime($tokenExpire);
    $interval = $currDateTime->diff($expireDateTime);
    $minutesLeft=$interval->format('%a') * 1440 + $interval->format('%H') * 60 + $interval->format('%I');
    if ($minutesLeft>30){ //if there is still more than 30mins left of token time, do not generate a new one
        return 0;
    }
    $ch = curl_init();
    $data_string='{"auth": {"tenantName": "' . $OpenStack_tenant_name . '", "passwordCredentials": {"username": "' . $OpenStack_user_name . '", "password": "' . $OpenStack_user_password . '"}}}';
    curl_setopt($ch, CURLOPT_URL, $OpenStack_service_url . ':35357/v2.0/tokens');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'Content-Length: ' . strlen($data_string))
    );
    $result = json_decode(curl_exec($ch), TRUE);
    curl_close($ch);
    $token = $result['access']['token']['id'];
    $tokenExpire=$result['access']['token']['expires'];
    $computeURL = $result['access']['serviceCatalog'][0]['endpoints'][0]['adminURL'];
    memcache_set($memcache, 'token', $token);
    memcache_set($memcache, 'tokenExpire', $tokenExpire);
    memcache_set($memcache, 'computeURL', $computeURL);
 //   print_r($result);
}
//############################################################################################
function updateHypervisorList(){
    include (dirname(__FILE__) . '/../../../functions/config.php');
    $config=array();
    $config=memcachedReadConfig();
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL,$config['computeURL'] . '/os-hypervisors/detail');
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'X-Auth-Token: ' . $config['token']
    ));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    $result = json_decode(curl_exec($ch), TRUE);
    curl_close($ch);
    $x=0;
    while ($x <  sizeof($result['hypervisors'])){
        $hypervisorName=$result['hypervisors'][$x]['service']['host'];
        $hypervisorIP=$result['hypervisors'][$x]['host_ip'];
        $hypervisorAddress=$result['hypervisors'][$x]['hypervisor_hostname'];
        if (!empty($hypervisorName) && !empty($hypervisorIP) && !empty($hypervisorAddress)){
            $hypervisorEntry=get_SQL_ARRAY("SELECT * FROM hypervisors WHERE name='$hypervisorName'");
            if (sizeof($hypervisorEntry) == 0){
                add_SQL_line("INSERT INTO hypervisors (name, ip, address2) VALUES ('$hypervisorName', '$hypervisorIP', '$hypervisorAddress')");
            }
            else
                add_SQL_line("UPDATE hypervisors SET name='$hypervisorName', ip='$hypervisorIP', address2='$hypervisorAddress' WHERE name='$hypervisorName'");
        }
        ++$x;
    }
//    print_r($result);
}
//############################################################################################
function updateVmList(){
    include (dirname(__FILE__) . '/../../../functions/config.php');
    $config=array();
    $config=memcachedReadConfig();
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL,$config['computeURL'] . '/servers/detail');
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'X-Auth-Token: ' . $config['token']
    ));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    $result = json_decode(curl_exec($ch), TRUE);
    curl_close($ch);
    $x=0;
    $instanceList=array();
    while ($x <  sizeof($result['servers'])){
        $vmName=$result['servers'][$x]['name'];
        $vmHypervisor=$result['servers'][$x]['OS-EXT-SRV-ATTR:host'];
        $vmInstanceName=$result['servers'][$x]['OS-EXT-SRV-ATTR:instance_name'];
        $vmInstanceId=$result['servers'][$x]['id'];
        if (!empty($vmName) && !empty($vmHypervisor) && !empty($vmInstanceName) && !empty($vmInstanceId)){
            $vmEntry=get_SQL_ARRAY("SELECT * FROM vms WHERE osInstanceId='$vmInstanceId'");
            array_push($instanceList,"'" . $vmInstanceId . "'");
            if (sizeof($vmEntry) == 0){
                add_SQL_line("INSERT INTO vms  (name, osHypervisorName,  osInstanceName,  osInstanceId) VALUES ('$vmName', '$vmHypervisor', '$vmInstanceName', '$vmInstanceId')");
            }
            else
                add_SQL_line("UPDATE vms SET name='$vmName', osHypervisorName='$vmHypervisor', osInstanceName='$vmInstanceName', osInstanceId='$vmInstanceId' WHERE osInstanceId='$vmInstanceId'");
        }
        ++$x;
    }
    $notToDelete=join(', ', $instanceList);
    if (!empty($toDelete))//delete all instances, that still exists in DB, but are removed in OpenStack
        add_SQL_line("DELETE FROM vms WHERE osInstanceId NOT IN ($notToDelete)");
    //print_r($result);
}
//############################################################################################
function reload_vm_info(){
}
//############################################################################################
function draw_dashboard_table(){
    openStackConnect();
    updateHypervisorList();
    updateVmList();
    echo '<div class="table-responsive"  style="overflow: inherit;">
            <table class="table table-striped table-hover" >
                <thead>
                    <tr>
                        <th>#</th>
                        <th></th>
                        <th>' . _("Machine name") . '</th>
                        <th>' . _("Machine type") . '</th>
                        <th>' . _("Source image") . '</th>
                        <th>' . _("Virt-snapshot") . '</th>
                        <th>' . _("Maintenance") . '</th>
                        <th>' . _("Operations") . '</th>
                        <th>' . _("OS type/Status/Used by") . '</th>
                    </tr>
                </thead>
                <tbody id="OpenstackVmTable">
                </tbody>
            </table>
        </div>';

}