require('dotenv').config();
const util = require('util');

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
    const aantalKm = 10;
    const rijksregisternummer = "";
    const sql = "UPDATE aanwezigheden SET z29 = 1, Kms = Kms + ?, punten = punten + 10, bedrag2022 = bedrag2022 + 0.5, totaalbedrag = totaalbedrag + 0.5, totaalpunten = totaalpunten + 10, aanweztotaal = aanweztotaal + 1 WHERE rijksregisternummer = ?;";

    const result = await connection.query(
      sql,
      [aantalKm, rijksregisternummer],
    );
    console.log(result[0].affectedRows + " record(s) updated");
    if (result[0].affectedRows !== 1) {
      console.error(`User ${rijksregisternummer} not found in our database`)
    }
    // await connection.commit()
    await connection.rollback();
  } catch (e) {
    console.error("Unexpected error ocurred", e);
    await connection.rollback();
  }

  await connection.end()
})();

// connection.connect(function(err) {
//   if (err) throw err;
//   console.log("Connected!");
//   con.query(sql, function (err, result) {
//     if (err) throw err;
//     console.log(result.affectedRows + " record(s) updated");
//   });
// });