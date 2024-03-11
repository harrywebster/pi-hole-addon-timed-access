# Pi-hole addon "timed-access"

An addon for Pi-hole to only permit access (resolve DNS queries) for clients between defined times of the day. Common use-case is for parents to limit access for their children on devices between given times of day and days of the week.

## Important

- if you want to change times/days/names, delete manually first from pi-hole, then run the toggle script... it will clear and re-build all the groups.
- any changes to the rules in config will require you to re-add clients to each group... it's like a factory reset.

## Prerequsites

- Pi-hole installed and operating on your network.
- SSH access to the Pi-hole server.
- You must disable random MAC addresses on the devices your which to apply timed-access to (if the device is a laptop/PC then ensure you disable this on the WiFi and ethernet connections, for android and apple devices, Google will help you here).
- When the `toggle.php` script runs to allow/block the traffic, the update isn't instant as the client will cache DNS lookups and if the client thinks the DNS server is down (which it will while blocking) then it'll take a few minutes for the block to be lifting to be realised by the device... however in my experience it tends to happen pretty quickly.
- Much like Pi-hole, this can be bypassed if the device supplies their own custom DNS servers (eg `8.8.8.8`)... To protect against this you should be restrict permission to update these details on the device.
- It maybe important to note that for testing I am running Pi-hole on an Ubuntu 22.04 x86_64 server with DHCP server enabled and additional adlists provided by `Blocklist Project GitHub` (see link at the bottom).

# Installation 

Clone this git repository on the machine where you're running pi-hole and edit the `config.json` to your requirements. Be sure that the JSON file is valid and follow the docs to use all the falgs/switches you need.

- `unique_prefix` this can be anything unique (alphanumeric) and is used to ensure when we create/delete records that we only affect records created by this tool, and no others you may have personally configured.
- `log` log file path where a copy of the debugging will go, if omitted then no file logging will take place.
- `pihole_path` path for the pi-hole PHP scripts, usually located `/var/www/html/admin/scripts/pi-hole/php/`
- `rules` 
  - `name`
  - `time_from`
  - `time_until`
  - `days`
  - `default_apply_block`

```json
{
	"unique_prefix": "hw4d0n",
	"pihole_path": "/var/www/html/admin/scripts/pi-hole/php/",
	"log": "/tmp/pihole-timed.log",
	"rules": [
        {
			"name": "children-pc",
			"time_from": "1800",
			"time_until": "2030",
			"days": ["sat", "sun"]
		},
		{
			"name": "children-tablet",
			"time_from": "0800",
			"time_until": "2000",
			"days": ["mon", "tue", "wed", "thu", "fri", "sat", "sun"]
		},
		{
			"name": "children-tele-weekend",
			"time_from": "0800",
			"time_until": "2330",
			"default_apply_block": true,
			"days": ["mon", "tue", "wed", "thu", "fri", "sat", "sun"]
		}
	]
}
```

## Pi-hole installed on Ubuntu/Debian (not in Docker)

```bash
sudo su -

echo "" >> /var/spool/cron/crontabs/root
echo "# Pihole toggle allow traffic to timed access devices every ten minutes" >> /var/spool/cron/crontabs/root
echo "*/10 * * * * /usr/bin/php /PATH-TO-REPO/pihole-timed-access.php" >> /var/spool/cron/crontabs/root
echo "" >> /var/spool/cron/crontabs/root
```

## Pi-hole installed using Docker

...

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

# Help or questions

Please feel free to message me via GitHub for any support or feature requests.

## TODO

- Fix permissions so it doesn't need to run as root
- Create POC for limited number of active hours - pick a client to only have 1 hour of activity per day for example

# References

- [Pi-hole GitHub](https://github.com/pi-hole/pi-hole)
- [Blocklist Project GitHub](https://github.com/blocklistproject/Lists)
