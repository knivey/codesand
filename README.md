## codesand is a rest server using amphp to run code inside containers

It requires LXD to be setup

Here is the process I've gone through to get set up. My system was running Debian GNU/Linux 10 (buster) and later Debian GNU/Linux 11 (bullseye)

You can skip the initial lxd setup if you already have lxc & lxd setup, just head to the commands to make the container

### Install lxc and lxd
Do the following commands as root:
```bash
apt install lxc debian-archive-keyring snapd
snap install core
snap install lxd
# this may be different for non debian, supposedly you could relogin too
export PATH=$PATH:/snap/bin
```
And now init your lxd system, its best to use a dedicated disk for the storage pool. Response time will be MUCH faster and you can then do proper blkio limits.

Read more in the manual about loop vs dedicated disk under "Where to store LXD data"
https://linuxcontainers.org/lxd/docs/master/storage
```bash
lxd init
```
### Make Container
Now you can create the container and enter it:
```bash
lxc launch images:debian/11 codesand
lxc exec codesand -- /bin/bash
```

### Inside the container:

Optional add sury repo for php8:
```bash
apt -y install apt-transport-https lsb-release ca-certificates curl wget
wget -O /etc/apt/trusted.gpg.d/php.gpg https://packages.sury.org/php/apt.gpg
sh -c 'echo "deb https://packages.sury.org/php/ $(lsb_release -sc) main" > /etc/apt/sources.list.d/php.list'
apt update
```
```bash
apt install php-cli  build-essential python python3 golang tcc tcl time fish zsh toilet figlet toilet-fonts cowsay default-jdk
#some php extensions
apt install php-bcmath php-curl php-mbstring php-sqlite3 php-tokenizer php-zip php-xml php-bz2 php-gmp
adduser codesand
adduser codesand games
```
Install anything else you might need for running scripts etc

If you install kotlin you need to do it manually because it seems snap can't run inside lxc

If you need to update or install things on all the containers later there are instructions to do that. 
```bash
exit
```

### Adjust container settings profile
```
lxc config set codesand boot.autostart=true
lxc profile copy default codesand
lxc profile edit codesand
```
Make it look like this adjust values to your liking.
The limits help stop abusive code from hogging machine.

The config options are explained here: https://linuxcontainers.org/lxd/docs/master/instances#keyvalue-configuration

*Notice I commented the network device*

The disk IO limit is rather untrustworthy and probably won't work at all with zfs. Due to caching you can write zeros way past the limit.. `cat /dev/zero > ~/zero` will still cause my whole server to grind down with this config.
```
config:
  limits.cpu.allowance: 30%
  limits.memory: 120MiB
  limits.processes: "50"
  limits.cpu.priority: "0"
description: Default codesand LXD profile
devices:
# Even lxc will not save your comments so write this down to save
#  eth0:
#    name: eth0
#    network: lxdbr0
#    type: nic
  root:
    path: /
    pool: default
    size: 3000MB
    limits.max: 200MB
    type: disk
name: codesand
used_by: []
```
Assign the modified profile to the container and make a snapshot so that it can be reset to this state after code runs.

```bash
lxc profile assign codesand codesand
lxc restart codesand
# if restart fails try bigger disk size above
lxc snapshot codesand default
```

### Add the user running bots to lxd group
```bash
usermod -a -G lxd USERNAME
```

### Setup "pool" of containers
Make at least a few containers, when one is busy we go down the list to find one to use. It takes several seconds for a container to reset and restart and become non-busy.

```bash
./makeContainers.php 10
./startAllContaiers.php
```

### Setup keys for access
Edit keys.yaml the file is simple
```yaml
keyname: key
keyname2: key2
```
etc...

### Running server
Make sure ```config.yaml``` is setup with where you would like to listen for connections

then just run ```main.php```

### LXC and cgroup v2
if you get this error:
```
WARNING: cgroup v2 is not fully supported yet, proceeding with partial confinement
```
Then you can get rid of it by editing /etc/default/grub

add ```systemd.unified_cgroup_hierarchy=0``` to ```GRUB_CMDLINE_LINUX```

run ```update-grub```

reboot

This will make your system use cgroups v1 **you should remove this when lxc fully supports cgroups v2**


### Installing packages and updates later
Switch the codesand container to the default profile (we shouldn't be using it for any api calls so no need to stop server yet)
```bash
lxc profile assign codesand default
lxc restart codesand
```
Enter the container to install packages or updates then exit
```bash
lxc exec codesand -- /bin/bash
```
When done, reassign the limited codesand profile and update snapshot
```bash
lxc profile assign codesand codesand
lxc restart codesand
lxc snapshot codesand default --reuse
```
You will need to shutdown the api server during the next part.

Delete all containers in the pool and then remake them from the updated codesand
```bash
lxc delete $(cat container.list) --force
rm container.list
./makeContainers.php 10
```
Then you may start the server again



