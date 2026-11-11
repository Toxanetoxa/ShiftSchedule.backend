SELECT 'CREATE DATABASE shiftschedule_testing'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'shiftschedule_testing')
\gexec
