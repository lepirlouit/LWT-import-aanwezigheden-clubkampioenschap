require('dotenv').config();
const aanwezigheden2024 = require('./aanwezigheden2024.json');

const mysql = require('mysql2/promise');

const databaseConfigs = {
  host: process.env.DB_HOST,
  user: process.env.DB_USER,
  password: process.env.DB_PASSWORD,
  database: process.env.DB_NAME,
};



(async () => {
  const connection = await mysql.createConnection(databaseConfigs)
  await connection.connect();
  console.log("Connected!");
  await connection.beginTransaction();
  try {
    for (const aanwezigheid of aanwezigheden2024) {

      const aantalKm = Math.round(aanwezigheid['Gereden kms'].replace(/,/, "."));
      const rijksregisternummer = aanwezigheid.NN;
      const sql = "UPDATE aanwezigheden SET z29 = 1, Kms = REPLACE(CAST(CAST(replace(Kms, ',', '.') AS DECIMAL(4)) + ? AS CHAR), '.', ','), punten = REPLACE(CAST(CAST(replace(punten, ',', '.') AS DECIMAL(4)) + 10 AS CHAR), '.', ','), bedrag2022 = REPLACE(CAST(CAST(replace(bedrag2022, ',', '.') AS DECIMAL(4,1)) + 0.5 AS CHAR), '.', ','), totaalbedrag = REPLACE(CAST(CAST(replace(totaalbedrag, ',', '.') AS DECIMAL(4,1)) + 0.5 AS CHAR), '.', ','), totaalpunten = REPLACE(CAST(CAST(replace(totaalpunten, ',', '.') AS DECIMAL(4)) + 10 AS CHAR), '.', ','), aanweztotaal = REPLACE(CAST(CAST(replace(aanweztotaal, ',', '.') AS DECIMAL(4)) + 1 AS CHAR), '.', ',') WHERE rijksregisternummer = ?;";
  
      const result = await connection.query(
        sql,
        [aantalKm, rijksregisternummer],
      );
      if (result[0].affectedRows !== 1) {
        console.error(`User ${JSON.stringify(aanwezigheid)} not found in our database`)
      }
    }
    await connection.commit()
    // await connection.rollback();
  } catch (e) {
    console.error("Unexpected error ocurred", e);
    await connection.rollback();
  }

  await connection.end()
})();
