# Pi-hole addon "timed-access"

An addon for Pi-hole to only permit access (resolve DNS queries) for clients between defined times of the day. Common use-case is for parents to limit access for their children on devices between given times of day.

## Prerequsites

- Pi-hole installed and operating on your network.
- SSH access to the Pi-hole server.
- You must disable random MAC addresses on the devices your which to apply timed-access to (if the device is a laptop/PC then ensure you disable this on the WiFi and ethernet connections, for android and apple devices, Google will help you here).
- When the `toggle.php` script runs to allow/block the traffic, the update isn't instant as the client will cache DNS lookups and if the client thinks the DNS server is down (which it will while blocking) then it'll take a few minutes for the block to be lifting to be realised by the device.
- Much like Pi-hole, this can be bypassed if the device supplies their own custom DNS servers (eg `8.8.8.8`)... To protect against this you should be restrict permission to update these details on the device.

# Installation 

## Pi-hole installed on Ubuntu/Debian (not in Docker)

```bash
sudo su -

echo "" >> /var/spool/cron/crontabs/root
echo "# Pihole toggle allow traffic to timed access devices every ten minutes" >> /var/spool/cron/crontabs/root
echo "*/10 * * * * /usr/bin/php /PATH-TO-REPO/toggle.php" >> /var/spool/cron/crontabs/root
echo "" >> /var/spool/cron/crontabs/root
```

## Pi-hole installed using Docker

...

# How to use

Once the installation is complete, go to your Pi-hole control panel and click on `Groups`. Create a new group with the name `allow-between-0800-2000` (which means allow access between 8AM and 8PM), feel free to change these times to your requirements, or add multiple (the group name MUST be formatted as shown exactly).

![Group](https://github.com/harrywebster/pi-hole-addon-timed-access/blob/main/screenshot/group.png?raw=true)

Now on the Pi-hole control panel click on `Domains`, select the `Regex filter` tab and under `Regular Expression` type `(\.|^)`, and for `Comment` type `block-everything` (this comment and regular expression must be exact).

![Domain](https://github.com/harrywebster/pi-hole-addon-timed-access/blob/main/screenshot/domain.png?raw=true)

Finally click on `Clients`, find the client you'd like to restrict access to (if they're not listed then you'll need to add them by MAC address a the top of this page)... click on `Group assignment` next to the client and ensure `allow-between-0800-2000` is checked.

![Client](https://github.com/harrywebster/pi-hole-addon-timed-access/blob/main/screenshot/client.png?raw=true)

That's it! The CRON job you've configured will now automatically toggle the access for this client on/off checking every ten minutes for a change. You can assign as many groups and clients to this as you like and changing the times in the group will automatically be picked up by the script running it the background.

## What is Pi-Hole

The Pi-hole is a DNS sinkhole that protects your devices from unwanted content without installing any client-side software. 

Read more about [Pi-hole here](https://github.com/pi-hole/pi-hole).

## Debugging

The default location for log file is `/tmp/pihole-timed-access.log`, so look here first.

For example:
```log
[2024-03-05 23:00:01] Nothing to do - 2300/800/2000 should be BLOCKING are be BLOCKING
[2024-03-05 23:05:25] Removing block for domainlist id 5 from group id 4
[2024-03-05 23:10:02] Nothing to do - 2310/800/2000 should be ALLOWING are be ALLOWING
```

## Blocklist Project

Additional helpful Adlists to enable can be found here, this is a great addition to further secure your network for parental control.

# Notes

... 

# References

- [Pi-hole GitHub](https://github.com/pi-hole/pi-hole)
- [Blocklist Project GitHub](https://github.com/blocklistproject/Lists)
