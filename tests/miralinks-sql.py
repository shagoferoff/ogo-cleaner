"""Opt-in real MariaDB test; needs the local user CSV decoded as JSON and compiled SQL directory."""
import csv, hashlib, json, os, secrets, subprocess, sys
from pathlib import Path
posts=json.loads(Path(sys.argv[1]).read_text())
compiled=Path(sys.argv[2]);plan=json.loads((compiled/'ogo-miralinks-plan.json').read_text());summary=json.loads((compiled/'ogo-miralinks-clean-summary.json').read_text())
client=os.environ.get('OGO_TEST_MYSQL_BIN','mariadb');socket=os.environ['OGO_TEST_MYSQL_SOCKET']
db='ogo_miralinks_sql_test_'+secrets.token_hex(5)
base=[client,'--no-defaults','--socket='+socket,'-u','root','--default-character-set=utf8mb4','--batch','--raw','--skip-column-names']
def query(sql):
 r=subprocess.run(base,input=sql.encode(),stdout=subprocess.PIPE,stderr=subprocess.PIPE)
 if r.returncode:raise RuntimeError(r.stderr.decode())
 return r.stdout.decode()
def sqlfile(name):return (compiled/name).read_text().replace('`ogo2023_ogo`','`'+db+'`')
def content_hashes():
 return {int(a):b for a,b in (line.split('\t') for line in query(f'SELECT ID,SHA2(BINARY post_content,256) FROM `{db}`.wp_posts ORDER BY ID;').splitlines())}
def change(id,extra):query(f"UPDATE `{db}`.wp_posts SET post_content=CONCAT(post_content,CONVERT(0x{extra.encode().hex()} USING utf8mb4)) WHERE ID={id};")
try:
 query(f'CREATE DATABASE `{db}` CHARACTER SET utf8mb4;')
 query(f'''CREATE TABLE `{db}`.wp_posts (ID bigint unsigned PRIMARY KEY,post_content LONGTEXT CHARACTER SET utf8mb4 NOT NULL,post_date datetime NOT NULL,post_status varchar(20) NOT NULL,post_type varchar(20) NOT NULL,post_title text NOT NULL,post_modified datetime NOT NULL,post_modified_gmt datetime NOT NULL) ENGINE=InnoDB;
 CREATE TABLE `{db}`.wp_postmeta (post_id bigint unsigned PRIMARY KEY,meta_key varchar(20),meta_value LONGTEXT CHARACTER SET utf8mb4) ENGINE=InnoDB;''')
 # Test fixture data remains local and never appears in the published SQL.
 inserts=[]
 for p in posts:
  id=int(p['ID']);body=p['post_content'].encode().hex();title=p['post_title'].encode().hex()
  inserts.append(f"INSERT INTO `{db}`.wp_posts VALUES ({id},CONVERT(0x{body} USING utf8mb4),'{p['post_date']}','publish','post',CONVERT(0x{title} USING utf8mb4),'2026-01-01 12:00:00','2026-01-01 10:00:00');")
  inserts.append(f"INSERT INTO `{db}`.wp_postmeta VALUES ({id},'anons','<a href=\"https://anons.example/\">Untouched</a>');")
 query('\n'.join(inserts))
 before=content_hashes();expected={int(p['ID']):hashlib.sha256(p['post_content'].encode()).hexdigest() for p in posts}
 assert before==expected
 metadata=query(f'SELECT * FROM `{db}`.wp_postmeta ORDER BY post_id;')
 dates=query(f'SELECT ID,post_date,post_status,post_type,HEX(post_title),post_modified,post_modified_gmt FROM `{db}`.wp_posts ORDER BY ID;')
 backup=summary['backup_table'].replace('`ogo2023_ogo`','`'+db+'`')
 apply=sqlfile('ogo-miralinks-clean.sql');restore=sqlfile('ogo-miralinks-restore.sql')
 prefix,updates=apply.split('-- CLEANUP PHASE:',1)
 query(prefix)
 assert content_hashes()==before,'Backup phase must not alter articles'
 assert int(query(f'SELECT COUNT(*) FROM {backup};').strip())==len(plan)
 query('-- CLEANUP PHASE:'+updates)
 after=content_hashes();intended={r['id']:r['after_sha'] for r in plan}
 assert after=={id:intended.get(id,sha) for id,sha in before.items()},'SQL offsets must produce exactly the analyzed content'
 assert query(f'SELECT * FROM `{db}`.wp_postmeta ORDER BY post_id;')==metadata
 assert query(f'SELECT ID,post_date,post_status,post_type,HEX(post_title),post_modified,post_modified_gmt FROM `{db}`.wp_posts ORDER BY ID;')==dates
 query(apply);assert content_hashes()==after,'Repeat apply must be idempotent'
 edited=plan[0]['id'];change(edited,'\nРедакторська правка 🐈');edited_sha=content_hashes()[edited]
 query(restore);rolled=content_hashes()
 assert rolled=={id:edited_sha if id==edited else sha for id,sha in before.items()},'Rollback must preserve later edits and restore other originals'
 # A stale snapshot must also be skipped before any backup is created.
 query(f'DROP TABLE {backup};')
 stale=plan[1]['id'];change(stale,'\nChanged since CSV')
 status_changed=plan[2]['id'];query(f"UPDATE `{db}`.wp_posts SET post_status='draft' WHERE ID={status_changed};")
 stable_before=content_hashes();query(apply);stable_after=content_hashes()
 assert stable_after[edited]==stable_before[edited] and stable_after[stale]==stable_before[stale]
 assert stable_after[status_changed]==before[status_changed]
 assert int(query(f'SELECT COUNT(*) FROM {backup};').strip())==len(plan)-3
 print(f'Passed full-data MariaDB checks: {len(posts)} articles, {len(plan)} updates; exact hashes, durable backups, unchanged metadata/dates, repeated apply, rollback and stale-edit/status protection.')
finally:
 query(f'DROP DATABASE IF EXISTS `{db}`;')
