## WARNING ##

MIB-based polling is experimental.  It might overload your LibreNMS server,
destroy your data, set your routers on fire, and kick your cat.  It has been
tested against a very limited set of devices (namely Ruckus ZD1000 wireless
controllers, and `net-snmp` on Linux).  It may fail badly on other hardware.

The approach taken is fairly simplistic and I claim no special expertise in
understanding MIBs.  Most of my knowledge of SNMP comes from reading net-snmp
man pages, and reverse engineering the output of snmptranslate and snmpwalk
and trying to make devices work with LibreNMS.  I may have made false
assumptions and probably use wrong terminology in many places.  Feel free to
offer corrections/suggestions via pull requests or email.

Paul Gear <paul@librenms.org>

## Overview ##

This is the 2nd experimental release of MIB polling.  If you used the 1st
release, you MUST perform a data conversion of the MIB-based polling files
using the script `contrib/convert-mib-graphs.sh`.  Failure to do so will
result in your data collection silently stopping.

MIB-based polling is disabled by default; you must set
    `$config['poller_modules']['mib'] = 1;`
in `config.php` to enable it.

## Preparation ##

MIB-based polling results in the creation of a separate RRD file for each
device-MIB-OID-index combination encountered by LibreNMS.  Thus it can cause
your disk space requirements to grow enormously and rapidly.  As an example,
enabling MIB-based polling on my test Ruckus ZD1000 wireless controller with
one (1) AP and one (1) user on that AP resulted in a 5 MB increase in RRD
space usage for that device.  Each RRD file is around 125 KB in size (on
x64-64 systems) and is pre-allocated, so after the first discovery and poller
run of each device with MIB-based polling enabled, disk space should be stable.
However, monitoring disk usage is your responsibility.  (The good news: you
can do this with LibreNMS. :-)

Unless you are running LibreNMS on a powerful system with pure SSD for RRD
storage, it is strongly recommended that you enable rrdcached and ensure it is
working *before* enabling MIB-based polling.

## Components ##

The components involved in MIB-based polling are:

### Discovery ###

  - OS discovery determines whether there are MIBs which should be polled.  If
    so, they are registered in the `device_mibs` association table as relevant
    to that device.  MIB associations for each device can be viewed at:
    http://your.librenms.server/device/device=XXXX/tab=mib/
    All MIBs used by MIB-based polling may be viewed at:
    http://your.librenms.server/mibs/
    All device associations created by MIB-based polling may be viewed at:
    http://your.librenms.server/mib_assoc/ (Devices -> MIB associations)

  - In addition, all devices are checked for a MIB matching their sysObjectID.
    If there is a matching MIB available, it is automatically included.
    The sysObjectID for each device is now displayed on the overview page:
    http://your.librenms.server/device/device=XXXX/

  - Note that the above means that no MIB-based polling will occur until the
    devices in question are rediscovered.  If you want to begin MIB-based
    polling immediately, you must force rediscovery from the web UI, or run it
    from the CLI using `./discovery.php -h HOSTNAME`

  - During discovery, relevant MIBs are parsed using `snmptranslate`, and the
    data returned is used to populate a database which guides the poller in
    what to store.  At the moment, only OIDs of INTEGER, Integer32, Gauge32,
    Unsigned32, Counter32, and Counter64 data types are parsed, and negative
    values are untested.

  - Devices may be excluded from MIB polling by changing the setting in the
    device edit screen:
    http://your.librenms.server/device/device=XXXX/tab=edit/section=modules/

### Polling ###

  - During polling the MIB associations for the device are looked up, and the
    MIB is polled for current values.  You can see the values which LibreNMS
    has retrieved from the MIB poller in the "Device MIB values" section of
    http://your.librenms.server/device/device=XXXX/tab=mib/

  - Data from the latest poll is saved in the table `device_oids`, and RRD
    files are saved in the relevant device directory as
    mibName-oidName-index.rrd

### Graphing ###

  - For each graph type defined in the database, a graph will appear in:
	http://your.librenms.server/device/device=XXXX/tab=graphs/group=mib/
  - MIB graphs are generated generically by
	`html/includes/graphs/device/mib.inc.php`
    There is presently no customisation of graphs available.
  - If there is only one index under a given OID, it is displayed as a normal
    line graph; if there multiple OIDs, they are displayed as a stacked graph.
    At the moment, all indices are placed in the same graph.  This is
    non-optimal for, e.g., wifi controllers with hundreds of APs attached.

### Alerting ###

There is no specific support for alerting in the MIB-based polling engine, but
the data it collects may be used in alerts.  Here's an example of an alert
which detects when a Ruckus wireless controller reports meshing disabled on an
access point:
    http://libertysys.com.au/imagebin/3btw98DR.png


## Adding/testing other device types ##

One of the goals of this work is to help take out the heavy lifting of adding
new device types.  Even if you want fully-customised graphs or tables, you can
still use the MIB-based poller to make it easy to gather the data you want to
graph.

### How to add a new device MIB ###

 1. Ensure the manufacturer's MIB is present in the mibs directory.  If you
    plan to submit your work to LibreNMS, make sure you attribute the source
    of the MIB, including the exact download URL if possible, or explicit
    instructions about how to obtain it.
 2. Check that `snmptranslate -Ts -M mibs -m MODULE | grep mibName` produces
    a named list of OIDs.  See the comments on `snmp_mib_walk()` in
    `includes/snmp.inc.php` for an example.
 3. Check that `snmptranslate -Td -On -M mibs -m MODULE MODULE::mibName`
    produces a parsed description of the OID values.  An example can be
    found in the comments for `snmp_mib_parse()` in `includes/snmp.inc.php`.
 4. Get the `sysObjectID` from a device, for example:
    ```snmpget -v2c -c public -OUsb -m SNMPv2-MIB -M /opt/librenms/mibs -t 30 hostname sysObjectID.0```
 5. Ensure `snmptranslate -m all -M /opt/librenms/mibs OID 2>/dev/null`
    (where OID is the value returned for sysObjectID above) results in a
    valid name for the MIB.  See the comments for `snmp_translate()` in
    `includes/snmp.inc.php` for an example.  If this step fails, it means
    there is something wrong with the MIB and `net-snmp` cannot parse it.
 6. Add any additional MIBs you wish to poll for specific device types to
    `includes/discovery/os/OSNAME.inc.php` by calling `poll_mibs()` with the
    MIB module and name.  See `includes/discovery/os/ruckuswireless.inc.php`
    for an example.
 7. That should be all you need to see MIB graphs!
 8. If you want to develop more customised support for a particular OS, you
    can follow the above process, then use the resultant data collected by
    LibreNMS in the RRD files or the database tables `device_oids`

## Configuration
### Main Configuration
In `/opt/librenms/config.php` add `$config['poller_modules']['mib'] = 1;`

### Discovery

You need to add your desired MIBs to `/opt/librenms/mibs` folder. Afterwards you need to register your MIBs to the discovery function. 

#### Example
`/opt/librenms/includes/discovery/os/f5.inc.php`

```
<?php
if (!$os || $os === 'linux') {
    $f5_sys_parent = '1.3.6.1.4.1.3375.2.1';
    if (strpos($sysObjectId, $f5_sys_parent)) {
        $os = 'f5';
   }

}

### MIB definition as an array 
$f5_mibs = array(
                "ltmVirtualServStatEntry" => "F5-BIGIP-LOCAL-MIB",
        );

### Actual registering of the MIB
register_mibs($device, $f5_mibs, "includes/discovery/os/f5.inc.php");

```

The important thing is the array $f5_mibs where you define which parts (ltmVirtualServStatEntry) of the MIB (F5-BIGIP-LOCAL-MIB) you are going to add. The registering is also important, otherwise poller cannot make use of the MIB.

## TODO ##

What's not included in MIB-based polling at present?  These may be present in
future versions.  Pull requests gratefully accepted!

  - Parse and save integer and timetick data types.
  - Filter MIBs/OIDs from being polled and/or saved.
  - Move graphs from the MIB section to elsewhere. e.g. If a device uses a
    unique MIB for CPU utilisation, we should display it under the relevant
    health tab.
  - Combine multiple MIB values into graphs automatically on a predefined or
    user-defined basis.
  - Include MIB types in stats submissions.
