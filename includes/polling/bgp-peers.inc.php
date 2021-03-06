<?php

if ($config['enable_bgp']) {

    $peers = dbFetchRows('SELECT * FROM bgpPeers WHERE device_id = ?', array($device['device_id']));

    if (!empty($peers)) {

        if ($device['os'] != 'junos') {
            $peer_data_check = snmpwalk_cache_oid($device, 'cbgpPeer2RemoteAs', array(), 'CISCO-BGP4-MIB', $config['mibdir']);
        }

        foreach ($peers as $peer) {
            //add context if exist
            $device['context_name']= $peer['context_name'];
            
            // Poll BGP Peer
            $peer2 = false;
            echo 'Checking BGP peer '.$peer['bgpPeerIdentifier'].' ';

            if (!empty($peer['bgpPeerIdentifier'])) {
                if (!strstr($peer['bgpPeerIdentifier'], ':') || $device['os'] != 'junos') {
                    // v4 BGP4 MIB
                    if (count($peer_data_check) > 0) {
                        $peer2 = true;
                    }

                    if ($peer2 === true) {
                        if (strstr($peer['bgpPeerIdentifier'], ':')) {
                            $bgp_peer_ident = ipv62snmp($peer['bgpPeerIdentifier']);
                        }
                        else {
                            $bgp_peer_ident = $peer['bgpPeerIdentifier'];
                        }

                        if (strstr($peer['bgpPeerIdentifier'], ':')) {
                            $ip_type = 2;
                            $ip_len  = 16;
                            $ip_ver  = 'ipv6';
                        }
                        else {
                            $ip_type = 1;
                            $ip_len  = 4;
                            $ip_ver  = 'ipv4';
                        }

                        $peer_identifier = $ip_type.'.'.$ip_len.'.'.$bgp_peer_ident;
                        $peer_data_tmp   = snmp_get_multi(
                            $device,
                            ' cbgpPeer2State.'.$peer_identifier.' cbgpPeer2AdminStatus.'.$peer_identifier.' cbgpPeer2InUpdates.'.$peer_identifier.' cbgpPeer2OutUpdates.'.$peer_identifier.' cbgpPeer2InTotalMessages.'.$peer_identifier.' cbgpPeer2OutTotalMessages.'.$peer_identifier.' cbgpPeer2FsmEstablishedTime.'.$peer_identifier.' cbgpPeer2InUpdateElapsedTime.'.$peer_identifier.' cbgpPeer2LocalAddr.'.$peer_identifier,
                            '-OQUs',
                            'CISCO-BGP4-MIB',
                            $config['mibdir']
                        );
                        $ident           = "$ip_ver.\"".$bgp_peer_ident.'"';
                        unset($peer_data);
                        $ident_key = array_keys($peer_data_tmp);
                        foreach ($peer_data_tmp[$ident_key[0]] as $k => $v) {
                            if (strstr($k, 'cbgpPeer2LocalAddr')) {
                                if ($ip_ver == 'ipv6') {
                                    $v = str_replace('"', '', $v);
                                    $v = rtrim($v);
                                    $v = preg_replace('/(\S+\s+\S+)\s/', '$1:', $v);
                                    $v = strtolower($v);
                                }
                                else {
                                    $v = hex_to_ip($v);
                                }
                            }

                            $peer_data .= "$v\n";
                        }
                    }
                    else {
                        // $peer_cmd  = $config['snmpget'].' -M '.$config['mibdir'].' -m BGP4-MIB -OUvq '.snmp_gen_auth($device).' '.$device['hostname'].':'.$device['port'].' '; 
                        $oids = "bgpPeerState." . $peer['bgpPeerIdentifier'] . " bgpPeerAdminStatus." . $peer['bgpPeerIdentifier'] . " bgpPeerInUpdates." . $peer['bgpPeerIdentifier'] . " bgpPeerOutUpdates." . $peer['bgpPeerIdentifier'] . " bgpPeerInTotalMessages." . $peer['bgpPeerIdentifier'] . " ";
                        $oids .= "bgpPeerOutTotalMessages." . $peer['bgpPeerIdentifier'] . " bgpPeerFsmEstablishedTime." . $peer['bgpPeerIdentifier'] . " bgpPeerInUpdateElapsedTime." . $peer['bgpPeerIdentifier'] . " ";
                        $oids .= "bgpPeerLocalAddr." . $peer['bgpPeerIdentifier'] . "";
                        $peer_data=snmp_get_multi($device,$oids,'-OUQs','BGP4-MIB');
                        $peer_data=  array_pop($peer_data);
                        if($debug){
                            var_dump($peer_data);  
                        }
                    }//end if
                    $bgpPeerState=  !empty($peer_data['bgpPeerState'])?$peer_data['bgpPeerState']:'';
                    $bgpPeerAdminStatus=  !empty($peer_data['bgpPeerAdminStatus'])?$peer_data['bgpPeerAdminStatus']:'';
                    $bgpPeerInUpdates=  !empty($peer_data['bgpPeerInUpdates'])?$peer_data['bgpPeerInUpdates']:'';
                    $bgpPeerOutUpdates=  !empty($peer_data['bgpPeerOutUpdates'])?$peer_data['bgpPeerOutUpdates']:'';
                    $bgpPeerInTotalMessages=  !empty($peer_data['bgpPeerInTotalMessages'])?$peer_data['bgpPeerInTotalMessages']:'';
                    $bgpPeerOutTotalMessages=  !empty($peer_data['bgpPeerOutTotalMessages'])?$peer_data['bgpPeerOutTotalMessages']:'';
                    $bgpPeerFsmEstablishedTime=  !empty($peer_data['bgpPeerFsmEstablishedTime'])?$peer_data['bgpPeerFsmEstablishedTime']:'';
                    $bgpPeerInUpdateElapsedTime=  !empty($peer_data['bgpPeerInUpdateElapsedTime'])?$peer_data['bgpPeerInUpdateElapsedTime']:'';
                    $bgpLocalAddr=  !empty($peer_data['bgpPeerLocalAddr'])?$peer_data['bgpPeerLocalAddr']:'';
                    //list($bgpPeerState, $bgpPeerAdminStatus, $bgpPeerInUpdates, $bgpPeerOutUpdates, $bgpPeerInTotalMessages, $bgpPeerOutTotalMessages, $bgpPeerFsmEstablishedTime, $bgpPeerInUpdateElapsedTime, $bgpLocalAddr) = explode("\n", $peer_data);
                    $bgpLocalAddr = str_replace('"', '', str_replace(' ', '', $bgpLocalAddr));
                    unset($peer_data);
                }
                else if ($device['os'] == 'junos') {
                    // v6 for JunOS via Juniper MIB
                    $peer_ip = ipv62snmp($peer['bgpPeerIdentifier']);

                    if (!isset($junos_v6)) {
                        echo "\nCaching Oids...";
                        // FIXME - needs moved to function
                        //$peer_cmd  = $config['snmpwalk'].' -M '.$config['mibdir'].'/junos -m BGP4-V2-MIB-JUNIPER -OUnq -'.$device['snmpver'].' '.snmp_gen_auth($device).' '.$device['hostname'].':'.$device['port'];

                        foreach (explode("\n",snmp_get($device,'jnxBgpM2PeerStatus.0.ipv6"','-OUnq','BGP4-V2-MIB-JUNIPER',$config['mibdir'] . "/junos")) as $oid){
                            list($peer_oid) = explode(' ', $oid);
                            $peer_id    = explode('.', $peer_oid);
                            $junos_v6[implode('.', array_slice($peer_id, 35))] = implode('.', array_slice($peer_id, 18));
                        }
                    }

                    // FIXME - move to function (and clean up, wtf?)
                    $oids = " jnxBgpM2PeerState.0.ipv6." . $junos_v6[$peer_ip];
                    $oids .= " jnxBgpM2PeerStatus.0.ipv6." . $junos_v6[$peer_ip]; # Should be jnxBgpM2CfgPeerAdminStatus but doesn't seem to be implemented?
                    $oids .= " jnxBgpM2PeerInUpdates.0.ipv6." . $junos_v6[$peer_ip];
                    $oids .= " jnxBgpM2PeerOutUpdates.0.ipv6." . $junos_v6[$peer_ip];
                    $oids .= " jnxBgpM2PeerInTotalMessages.0.ipv6." . $junos_v6[$peer_ip];
                    $oids .= " jnxBgpM2PeerOutTotalMessages.0.ipv6." . $junos_v6[$peer_ip];
                    $oids .= " jnxBgpM2PeerFsmEstablishedTime.0.ipv6." . $junos_v6[$peer_ip];
                    $oids .= " jnxBgpM2PeerInUpdatesElapsedTime.0.ipv6." . $junos_v6[$peer_ip];
                    $oids .= " jnxBgpM2PeerLocalAddr.0.ipv6." . $junos_v6[$peer_ip];
                    //$peer_cmd .= '|grep -v "No Such Instance"'; WHAT TO DO WITH THIS??,USE TO SEE -Ln?? 
                    $peer_data=snmp_get_multi($device,$oids,'-OUQs -Ln','BGP4-V2-MIB-JUNIPER',$config['mibdir'] . "/junos");
                    $peer_data=  array_pop($peer_data);
                    if($debug){
                      var_dump($peer_data);
                    }
                    $bgpPeerState=  !empty($peer_data['bgpPeerState'])?$peer_data['bgpPeerState']:'';
                    $bgpPeerAdminStatus=  !empty($peer_data['bgpPeerAdminStatus'])?$peer_data['bgpPeerAdminStatus']:'';
                    $bgpPeerInUpdates=  !empty($peer_data['bgpPeerInUpdates'])?$peer_data['bgpPeerInUpdates']:'';
                    $bgpPeerOutUpdates=  !empty($peer_data['bgpPeerOutUpdates'])?$peer_data['bgpPeerOutUpdates']:'';
                    $bgpPeerInTotalMessages=  !empty($peer_data['bgpPeerInTotalMessages'])?$peer_data['bgpPeerInTotalMessages']:'';
                    $bgpPeerOutTotalMessages=  !empty($peer_data['bgpPeerOutTotalMessages'])?$peer_data['bgpPeerOutTotalMessages']:'';
                    $bgpPeerFsmEstablishedTime=  !empty($peer_data['bgpPeerFsmEstablishedTime'])?$peer_data['bgpPeerFsmEstablishedTime']:'';
                    $bgpPeerInUpdateElapsedTime=  !empty($peer_data['bgpPeerInUpdateElapsedTime'])?$peer_data['bgpPeerInUpdateElapsedTime']:'';
                    $bgpLocalAddr=  !empty($peer_data['bgpPeerLocalAddr'])?$peer_data['bgpPeerLocalAddr']:'';

                    unset($peer_data);

                    d_echo("State = $bgpPeerState - AdminStatus: $bgpPeerAdminStatus\n");

                    $bgpLocalAddr = str_replace('"', '', str_replace(' ', '', $bgpLocalAddr));
                    if ($bgpLocalAddr == '00000000000000000000000000000000') {
                        $bgpLocalAddr = '';
                        // Unknown?
                    }
                    else {
                        $bgpLocalAddr = strtolower($bgpLocalAddr);
                        for ($i = 0; $i < 32; $i += 4) {
                            $bgpLocalAddr6[] = substr($bgpLocalAddr, $i, 4);
                        }

                        $bgpLocalAddr = Net_IPv6::compress(implode(':', $bgpLocalAddr6));
                        unset($bgpLocalAddr6);
                    }
                }//end if
            }//end if

            if ($bgpPeerFsmEstablishedTime) {
                if (!(is_array($config['alerts']['bgp']['whitelist']) && !in_array($peer['bgpPeerRemoteAs'], $config['alerts']['bgp']['whitelist'])) && ($bgpPeerFsmEstablishedTime < $peer['bgpPeerFsmEstablishedTime'] || $bgpPeerState != $peer['bgpPeerState'])) {
                    if ($peer['bgpPeerState'] == $bgpPeerState) {
                        log_event('BGP Session Flap: '.$peer['bgpPeerIdentifier'].' (AS'.$peer['bgpPeerRemoteAs'].')', $device, 'bgpPeer', $bgpPeer_id);
                    }
                    else if ($bgpPeerState == 'established') {
                        log_event('BGP Session Up: '.$peer['bgpPeerIdentifier'].' (AS'.$peer['bgpPeerRemoteAs'].')', $device, 'bgpPeer', $bgpPeer_id);
                    }
                    else if ($peer['bgpPeerState'] == 'established') {
                        log_event('BGP Session Down: '.$peer['bgpPeerIdentifier'].' (AS'.$peer['bgpPeerRemoteAs'].')', $device, 'bgpPeer', $bgpPeer_id);
                    }
                }
            }

            $peerrrd = $config['rrd_dir'].'/'.$device['hostname'].'/'.safename('bgp-'.$peer['bgpPeerIdentifier'].'.rrd');
            if (!is_file($peerrrd)) {
                $create_rrd = 'DS:bgpPeerOutUpdates:COUNTER:600:U:100000000000
                    DS:bgpPeerInUpdates:COUNTER:600:U:100000000000
                    DS:bgpPeerOutTotal:COUNTER:600:U:100000000000
                    DS:bgpPeerInTotal:COUNTER:600:U:100000000000
                    DS:bgpPeerEstablished:GAUGE:600:0:U '.$config['rrd_rra'];

                rrdtool_create($peerrrd, $create_rrd);
            }

            $fields = array(
                'bgpPeerOutUpdates'    => $bgpPeerOutUpdates,
                'bgpPeerInUpdates'     => $bgpPeerInUpdates,
                'bgpPeerOutTotal'      => $bgpPeerOutTotalMessages,
                'bgpPeerInTotal'       => $bgpPeerInTotalMessages,
                'bgpPeerEstablished'   => $bgpPeerFsmEstablishedTime,
            );
            rrdtool_update("$peerrrd", $fields);

            $tags = array('bgpPeerIdentifier' => $peer['bgpPeerIdentifier']);
            influx_update($device,'bgp',$tags,$fields);

            $peer['update']['bgpPeerState']              = $bgpPeerState;
            $peer['update']['bgpPeerAdminStatus']        = $bgpPeerAdminStatus;
            $peer['update']['bgpPeerFsmEstablishedTime'] = $bgpPeerFsmEstablishedTime;
            $peer['update']['bgpPeerInUpdates']          = $bgpPeerInUpdates;
            $peer['update']['bgpLocalAddr']              = $bgpLocalAddr;
            $peer['update']['bgpPeerOutUpdates']         = $bgpPeerOutUpdates;

            dbUpdate($peer['update'], 'bgpPeers', '`device_id` = ? AND `bgpPeerIdentifier` = ?', array($device['device_id'], $peer['bgpPeerIdentifier']));

            if ($device['os_group'] == 'cisco' || $device['os'] == 'junos') {
                // Poll each AFI/SAFI for this peer (using CISCO-BGP4-MIB or BGP4-V2-JUNIPER MIB)
                $peer_afis = dbFetchRows('SELECT * FROM bgpPeers_cbgp WHERE `device_id` = ? AND bgpPeerIdentifier = ?', array($device['device_id'], $peer['bgpPeerIdentifier']));
                foreach ($peer_afis as $peer_afi) {
                    $afi  = $peer_afi['afi'];
                    $safi = $peer_afi['safi'];
                    d_echo("$afi $safi\n");

                    if ($device['os_group'] == 'cisco') {
                        $bgp_peer_ident = ipv62snmp($peer['bgpPeerIdentifier']);
                        if (strstr($peer['bgpPeerIdentifier'], ':')) {
                            $ip_type = 2;
                            $ip_len  = 16;
                            $ip_ver  = 'ipv6';
                        }
                        else {
                            $ip_type = 1;
                            $ip_len  = 4;
                            $ip_ver  = 'ipv4';
                        }

                        $ip_cast = 1;
                        if ($peer_afi['safi'] == 'multicast') {
                            $ip_cast = 2;
                        }
                        else if ($peer_afi['safi'] == 'unicastAndMulticast') {
                            $ip_cast = 3;
                        }
                        else if ($peer_afi['safi'] == 'vpn') {
                            $ip_cast = 128;
                        }

                        $check = snmp_get($device, 'cbgpPeer2AcceptedPrefixes.'.$ip_type.'.'.$ip_len.'.'.$bgp_peer_ident.'.'.$ip_type.'.'.$ip_cast, '', 'CISCO-BGP4-MIB', $config['mibdir']);

                        if (!empty($check)) {
                            $cgp_peer_identifier = $ip_type.'.'.$ip_len.'.'.$bgp_peer_ident.'.'.$ip_type.'.'.$ip_cast;
                            $cbgp_data_tmp       = snmp_get_multi(
                                $device,
                                ' cbgpPeer2AcceptedPrefixes.'.$cgp_peer_identifier.' cbgpPeer2DeniedPrefixes.'.$cgp_peer_identifier.' cbgpPeer2PrefixAdminLimit.'.$cgp_peer_identifier.' cbgpPeer2PrefixThreshold.'.$cgp_peer_identifier.' cbgpPeer2PrefixClearThreshold.'.$cgp_peer_identifier.' cbgpPeer2AdvertisedPrefixes.'.$cgp_peer_identifier.' cbgpPeer2SuppressedPrefixes.'.$cgp_peer_identifier.' cbgpPeer2WithdrawnPrefixes.'.$cgp_peer_identifier,
                                '-OQUs',
                                'CISCO-BGP4-MIB',
                                $config['mibdir']
                            );
                            $ident               = "$ip_ver.\"".$peer['bgpPeerIdentifier'].'"'.'.'.$ip_type.'.'.$ip_cast;
                            unset($cbgp_data);
                            $temp_keys = array_keys($cbgp_data_tmp);
                            unset($temp_data);
                            $temp_data['cbgpPeer2AcceptedPrefixes']     = $cbgp_data_tmp[$temp_keys[0]]['cbgpPeer2AcceptedPrefixes'];
                            $temp_data['cbgpPeer2DeniedPrefixes']       = $cbgp_data_tmp[$temp_keys[0]]['cbgpPeer2DeniedPrefixes'];
                            $temp_data['cbgpPeer2PrefixAdminLimit']     = $cbgp_data_tmp[$temp_keys[0]]['cbgpPeer2PrefixAdminLimit'];
                            $temp_data['cbgpPeer2PrefixThreshold']      = $cbgp_data_tmp[$temp_keys[0]]['cbgpPeer2PrefixThreshold'];
                            $temp_data['cbgpPeer2PrefixClearThreshold'] = $cbgp_data_tmp[$temp_keys[0]]['cbgpPeer2PrefixClearThreshold'];
                            $temp_data['cbgpPeer2AdvertisedPrefixes']   = $cbgp_data_tmp[$temp_keys[0]]['cbgpPeer2AdvertisedPrefixes'];
                            $temp_data['cbgpPeer2SuppressedPrefixes']   = $cbgp_data_tmp[$temp_keys[0]]['cbgpPeer2SuppressedPrefixes'];
                            $temp_data['cbgpPeer2WithdrawnPrefixes']    = $cbgp_data_tmp[$temp_keys[0]]['cbgpPeer2WithdrawnPrefixes'];
                            foreach ($temp_data as $k => $v) {
                                $cbgp_data .= "$v\n";
                            }

                            d_echo("$cbgp_data\n");
                        }
                        else {
                            // FIXME - move to function
                            $oids = " cbgpPeerAcceptedPrefixes." . $peer['bgpPeerIdentifier'] . ".$afi.$safi";
                            $oids .= " cbgpPeerDeniedPrefixes." . $peer['bgpPeerIdentifier'] . ".$afi.$safi";
                            $oids .= " cbgpPeerPrefixAdminLimit." . $peer['bgpPeerIdentifier'] . ".$afi.$safi";
                            $oids .= " cbgpPeerPrefixThreshold." . $peer['bgpPeerIdentifier'] . ".$afi.$safi";
                            $oids .= " cbgpPeerPrefixClearThreshold." . $peer['bgpPeerIdentifier'] . ".$afi.$safi";
                            $oids .= " cbgpPeerAdvertisedPrefixes." . $peer['bgpPeerIdentifier'] . ".$afi.$safi";
                            $oids .= " cbgpPeerSuppressedPrefixes." . $peer['bgpPeerIdentifier'] . ".$afi.$safi";
                            $oids .= " cbgpPeerWithdrawnPrefixes." . $peer['bgpPeerIdentifier'] . ".$afi.$safi";
                            
                            d_echo("$oids\n");
                            
                            $cbgp_data=  snmp_get_multi($device,$oids,'-OUQs ','CISCO-BGP4-MIB');
                            $cbgp_data=  array_pop($cbgp_data);
                            d_echo("$cbgp_data\n");
                        }//end if
                        $cbgpPeerAcceptedPrefixes=  !empty($cbgp_data['cbgpPeerAcceptedPrefixes'])?$cbgp_data['cbgpPeerAcceptedPrefixes']:'';
                        $cbgpPeerDeniedPrefixes=  !empty($cbgp_data['cbgpPeerDeniedPrefixes'])?$cbgp_data['cbgpPeerDeniedPrefixes']:'';
                        $cbgpPeerPrefixAdminLimit=  !empty($cbgp_data['cbgpPeerPrefixAdminLimit'])?$cbgp_data['cbgpPeerPrefixAdminLimit']:'';
                        $cbgpPeerPrefixThreshold=  !empty($cbgp_data['cbgpPeerPrefixThreshold'])?$cbgp_data['cbgpPeerPrefixThreshold']:'';
                        $cbgpPeerPrefixClearThreshold=  !empty($cbgp_data['cbgpPeerPrefixClearThreshold'])?$cbgp_data['cbgpPeerPrefixClearThreshold']:'';
                        $cbgpPeerAdvertisedPrefixes=  !empty($cbgp_data['cbgpPeerAdvertisedPrefixes'])?$cbgp_data['cbgpPeerAdvertisedPrefixes']:'';
                        $cbgpPeerSuppressedPrefixes=  !empty($cbgp_data['cbgpPeerSuppressedPrefixes'])?$cbgp_data['cbgpPeerSuppressedPrefixes']:'';
                        $cbgpPeerWithdrawnPrefixes=  !empty($cbgp_data['cbgpPeerWithdrawnPrefixes'])?$cbgp_data['cbgpPeerWithdrawnPrefixes']:'';
                        unset($cbgp_data);
                    }//end if

                    // FIXME THESE FIELDS DO NOT EXIST IN THE DATABASE!
                    $update = 'UPDATE bgpPeers_cbgp SET';
                    $peer['c_update']['AcceptedPrefixes']     = $cbgpPeerAcceptedPrefixes;
                    $peer['c_update']['DeniedPrefixes']       = $cbgpPeerDeniedPrefixes;
                    $peer['c_update']['PrefixAdminLimit']     = $cbgpPeerAdminLimit;
                    $peer['c_update']['PrefixThreshold']      = $cbgpPeerPrefixThreshold;
                    $peer['c_update']['PrefixClearThreshold'] = $cbgpPeerPrefixClearThreshold;
                    $peer['c_update']['AdvertisedPrefixes']   = $cbgpPeerAdvertisedPrefixes;
                    $peer['c_update']['SuppressedPrefixes']   = $cbgpPeerSuppressedPrefixes;
                    $peer['c_update']['WithdrawnPrefixes']    = $cbgpPeerWithdrawnPrefixes;


                    $oids = array(
                        'AcceptedPrefixes',
                        'DeniedPrefixes',
                        'AdvertisedPrefixes',
                        'SuppressedPrefixes',
                        'WithdrawnPrefixes',
                    );

                    foreach ($oids as $oid) {
                        $peer['c_update'][$oid.'_delta'] = $peer['c_update'][$oid] - $peer_afi[$oid];
                        $peer['c_update'][$oid.'_prev'] = $peer_afi[$oid];
                    }

                    dbUpdate($peer['c_update'], 'bgpPeers_cbgp', '`device_id` = ? AND bgpPeerIdentifier = ? AND afi = ? AND safi = ?', array($device['device_id'], $peer['bgpPeerIdentifier'], $afi, $safi));

                    $cbgp_rrd = $config['rrd_dir'].'/'.$device['hostname'].'/'.safename('cbgp-'.$peer['bgpPeerIdentifier'].".$afi.$safi.rrd");
                    if (!is_file($cbgp_rrd)) {
                        $rrd_create = 'DS:AcceptedPrefixes:GAUGE:600:U:100000000000
                            DS:DeniedPrefixes:GAUGE:600:U:100000000000
                            DS:AdvertisedPrefixes:GAUGE:600:U:100000000000
                            DS:SuppressedPrefixes:GAUGE:600:U:100000000000
                            DS:WithdrawnPrefixes:GAUGE:600:U:100000000000 '.$config['rrd_rra'];
                        rrdtool_create($cbgp_rrd, $rrd_create);
                    }

                    $fields = array(
                        'AcceptedPrefixes'    => $cbgpPeerAcceptedPrefixes,
                        'DeniedPrefixes'      => $cbgpPeerDeniedPrefixes,
                        'AdvertisedPrefixes'  => $cbgpPeerAdvertisedPrefixes,
                        'SuppressedPrefixes'  => $cbgpPeerSuppressedPrefixes,
                        'WithdrawnPrefixes'   => $cbgpPeerWithdrawnPrefixes,
                    );
                    rrdtool_update("$cbgp_rrd", $fields);

                    $tags = array('bgpPeerIdentifier' => $peer['bgpPeerIdentifier'], 'afi' => $afi, 'safi' => $safi);
                    influx_update($device,'cbgp',$tags,$fields);

                } //end foreach
            } //end if
            echo "\n";
        } //end foreach
    } //end if
} //end if
