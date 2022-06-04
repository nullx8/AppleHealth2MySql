AppleHealth2MySql
Sync Apple Health data to Mysql
============

very non-sphisticated code sniplets to import Apple Health Data into a MySql Database.

the Actual Apple->json Export is handled by a paid software called "Health Auto Export"
i just made the part to put the JSON formated exports into a MySql Database (nothing really sophisticated)

it simply loops trough the data, and creates Tables for the dataset.

the DATE will be used as a primary Key,
without any data present, the creation of thee table will fail. resulting in tables are only created when actual data is present.
re-import of exiting data is no problem as the date is used as a Primary key (importing duplicates will fail on a row by row basis.

Required things:
- LAMP instance
- Health Auto Export for iOS
  https://0x8.in.th/random/automating-health-data-apple-health-export-to-mysql
- a iPhone
- some coffee, 230 Volts

ToDo:
- make the code usable by anyone else but me
- create proper stats output by ios.php (so it can be seen in the response window in the export app.
- deal with the coder to ensure it exports in the background
  (for some reason this only works with the building tasks but somewhat fails on API export)

Changelog:
- Collection of basic structural ideas.
