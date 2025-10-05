--Если сеанс только что стартовал, очистить таблицы urls и url_checks:

DROP TABLE IF EXISTS url_checks;
DROP TABLE IF EXISTS urls CASCADE;

--Если таблицы urls и url_checks не существуют, то создать их:

CREATE TABLE urls (
                      id bigint PRIMARY KEY GENERATED ALWAYS AS IDENTITY,
                      name varchar(255) NOT NULL UNIQUE,
                      created_at timestamp DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE url_checks (
                            id bigint PRIMARY KEY GENERATED ALWAYS AS IDENTITY,
                            url_id bigint NOT NULL,
                            status_code smallint,
                            h1 varchar(255),
                            title varchar(255),
                            description text,
                            created_at timestamp DEFAULT CURRENT_TIMESTAMP
                            FOREIGN KEY (url_id) REFERENCES urls (id)
);
