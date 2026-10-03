# Wisp

Sell game servers on a [WISP](https://wisp.gg) panel from WemX. Each order creates a panel user when needed, then deploys a server to one of the allowed locations.

## Features

- Application API connection, plus an admin client API key for power actions and live usage
- Package nest and egg, with the egg's variables, Docker image, and startup command loaded after saving
- CPU, memory, disk, swap, block IO, and CPU pinning limits
- Database and backup size limits
- Selectable locations, with an optional location picked by the customer at checkout
- Optional dedicated IP
- Create a panel user for the customer and email the login
- Server address, status, and live CPU, memory, and disk usage on the order page
- Start, stop, restart, and kill buttons on the order page
- Suspend, unsuspend, terminate, upgrade, and password changes

## Install

Install from the WemX marketplace, or download `Wisp.zip` from a GitHub release and place the `Wisp` folder at `extensions/Servers/Wisp`. Then enable **Wisp**.

Publishing a release builds `Wisp.zip`. Unzipping it creates a folder named `Wisp`. GitHub’s own “Source code” archive still unpacks to `server-wisp-<tag>`. Use `Wisp.zip`.

## Connection

- **Hostname**: the panel URL without a trailing slash, for example `https://panel.example.com`
- **API Key**: an application API key
- **Client API Key**: a client API key of an administrator account, used for power actions and resource usage

## Packages

Enter the nest and egg IDs, then save the package once so WemX can load that egg's variables, Docker image, and startup command.

**Selectable Locations** is a comma separated list of location IDs, for example `1,3`. The available locations are listed under the field. To let customers pick a location, add **Location** as a select configurable option with the location IDs as values.

Memory, disk, swap, and backup size are entered in MB. `0` means unlimited for CPU, memory, and disk.
