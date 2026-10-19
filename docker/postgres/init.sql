-- Runs once, when the data volume is created. The test suite gets its own
-- database so running tests never touches development data.
CREATE DATABASE beauty_test OWNER beauty;
