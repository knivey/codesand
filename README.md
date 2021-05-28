##This script is to run code snipperts given to the bot on irc channels and display the results.

It requires LXD to be setup


No idea if ive done this the best way but here's the commands I've run to get set up. My system was running Debian GNU/Linux 10 (buster)
lxd requires snap to be setup.

You can skip much of this if you already have lxc/lxd setup just need the commands to make the container

###Do the following commands as root
```bash
apt install lxc debian-archive-keyring snapd
snap install core
snap install lxd
# this may be different for non debian, supposedly you could relogin too
export PATH=$PATH:/snap/bin
# I just used all defaults for init
lxd init
```
Now you can create the container and enter it:
```bash
lxc launch images:debian/10 codesand
lxc exec codesand -- /bin/bash
```

###Inside the container:

```
apt update && apt upgrade
apt install php-cli build-essential python golang
adduser codesand
```
Install anything else you might need for running scripts etc

If you need to update or install things on all the containers later there are scripts to do that. 
```bash
exit
```

###Adjust container settings profile
```
lxc config set codesand boot.autostart=true
lxc profile copy default codesand
lxc profile edit codesand
```
Make it look like this adjust values to your liking.
The limits help stop abusive code from hogging machine.

The config options are explained here: https://linuxcontainers.org/lxd/docs/master/instances#keyvalue-configuration

*Notice I commented the network device*

The disk IO limit is rather untrustworthy and probably wont work at all with zfs. Due to caching you can write zeros way past the limit.. `cat /dev/zero > ~/zero` will still cause my whole server to grind down with this config.
```
config:
  limits.cpu.allowance: 10%
  limits.memory: 80MiB
  limits.processes: "50"
  limits.cpu.priority: "0"
description: Default codesand LXD profile
devices:
#just comment incase want to enable again
#  eth0:
#    name: eth0
#    network: lxdbr0
#    type: nic
  root:
    path: /
    pool: default
    size: 2GB
    limits.max: 10MB
    type: disk
name: codesand
used_by: []
```
Assign the modified profile to the container and make a snapshot so it can be reset to this state after code runs.

```bash
lxc profile assign codesand codesand
lxc snapshot codesand default
```

### Add the user running bots to lxd group
```bash
usermod -a -G lxd USERNAME
```

### Setup "pool" of containers
I recommend making at least a few container copies and the script will rotate them. It takes several seconds for a container to reset and restart.

```bash
./makeContainers.php 10
./startAllContaiers.php
```

### Other thoughts
Using lxd the containers are already ran unprivileged.


Basically the execution process will do as follows:
* copy code to a file on instance
  ```
  lxc file push test.php codesand/home/codesand/
  lxc exec codesand -- /bin/chown codesand:codesand /home/codesand/test.php
  ```
* execute that file
  ```
  timeout 15 lxc exec codesand -- su --login codesand -c 'php test.php'
  ```
* reset instance to default snapshot
  ```
  lxc restore codesand default
  ```
  seems to take about 10 seconds?


###Things to consider:
* Timeout on execution
  * Instance root will killall -u codesand after time is up
  * timeout command wont work with forkbombs, we will need to 
  * Want to be able to show in channel reply that it timed out.
* How OOM and forkbomb etc gets handled
  * Forkbombs I think will be handled ok with timeout and default ulimits
  * OOM hope and pray
