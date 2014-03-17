SFArchiveBundle
===============


Purpose
-------

The purpose of this bundle is to provide a generic Symfony2 Console Command which is used to archive DB tables. The general idea is that you give it a table name, and a strategy that it should follow to archive.

Say you have 100 000 000 records in a table called "**stuff**". Let's assume for a second that the table called "**stuff**" has a **created_at** field attached to it, which is a date-time field in MySQL (or whatever your underlying database is).

When calling the command <code>php app/console archive:generic stuff</code> it will run through the table **stuff** and archive the values into tables starting with a "z" and ending in the "Ym" value corresponding to the record's **created_at** column.

As an example, a record having **created_at** of "2014-03-11 23:54:22" will be archived to the table with name zstuff201403. And record having **created_at** of "2014-02-28 08:23:00" will go to zstuff201402 - notice the difference is in the month at the end.

The rational behind this is that tables starting with z will be at the end of your list of tables, in an effort to avoid cluttering your main view of your database.

Usage
-----

	Usage:
	 archive:generic [--days="..."] [--strategy-z-tables[="..."]] [--strategy-source-field="..."] [--batch-size[="..."]] [--source-entity-manager="..."] [--destination-entity-manager="..."] table_name

	Arguments:
	 table_name                    The name of the source table to archive

	Options:
	 --days                        Defines how many days in the past to start archive. Example, 14 would archive data older than 14 days (default: 90) 
	 --strategy-z-tables           Strategy is to create tables of the same name, in format zTableNameYm (ex zclients201403. (default: "Ym")
	 --strategy-source-field       Field to base the strategy on. For the YYYYMM strategy, this has to be a date field (default: "created_at")
	 --batch-size                  Size of each batch. It is important not to choose too big or small a batch. Big batches will cause memory problems, small ones will decrease the speed of archiving. (default: 100)
	 --source-entity-manager       Source Entity Manager to archive from (default: "default")
	 --destination-entity-manager  Destination Entity Manager to archive to. If none is provided, the same as --source-entity-manager will be used. (default: "default">
	 
Reporting an issue or a feature request
---------------------------------------

Please submit issues and feature requests in the [Github issue tracker](https://github.com/sarelvdwalt/SFArchiveBundle/issues).
