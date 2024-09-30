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
    // const sql = "UPDATE customers SET address = 'Canyon 123' WHERE address = 'Valley 345'";
    // const result = await connection.query(sql);
    // console.log(result.affectedRows + " record(s) updated");

    // await connection.commit()
    await connection.rollback();
  } catch (e) {
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