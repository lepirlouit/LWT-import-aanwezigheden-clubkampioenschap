require('dotenv').config();
const path = require('node:path');
const fs = require('node:fs');
const directoryPath = './';
const filter = /blankenberge.csv/;
// const filter = /demo.TXT/;
const mysql = require('mysql2/promise');

const databaseConfigs = {
  host: process.env.DB_HOST,
  user: process.env.DB_USER,
  password: process.env.DB_PASSWORD,
  database: process.env.DB_NAME,
};

let connection = null;

function listFilesInDirMatchingFilter(startPath, filter) {
  const matchingFiles = [];
  //console.log('Starting from dir '+startPath+'/');

  if (!fs.existsSync(startPath)) {
      console.log("no dir ", startPath);
      return;
  }

  const files = fs.readdirSync(startPath);
  for (const file of files) {
      const filename = path.join(startPath, file);
      const stat = fs.lstatSync(filename);
      if (stat.isDirectory()) {
        matchingFiles.push(...listFilesInDirMatchingFilter(filename, filter)); //recurse
      } else if (filter.test(filename)) {

        matchingFiles.push(filename);
      }
  };
  return matchingFiles;
};

/**
 * A point on a two dimensional plane.
 * @typedef {Object} ParsedLine
 * @property {string} name - The full name
 * @property {number} points - aantal punten
 */

/**
 * 
 * @param {string} filePath 
 * @returns {ParsedLine[]}
 */
const parseFile = (filePath) => {
  const content = fs.readFileSync(filePath).toString();
  const lines = content.split('\n').filter(l => l);
  return lines.map(line => {
    const [Naam,NN,datum,aard,ploeg,kilometers,punten,title,Commentaar] = line.split(',');
    return ({
      name: Naam,
      niss: NN,
      date:new Date(datum.split('/').reverse().join('/')) ,
      aard,
      ploeg,
      kilometers: parseInt(kilometers),
      title,Commentaar,
      points: parseInt(punten),
    });
  });
}

const insertActiviteitenRecord = async ({ niss, isoDate, title, aantalKm, teamName })  => {
  const rijksregisternummer = niss;
  const sql = 'INSERT INTO activiteiten (datum, rijksregisternummer, aard, ploeg, kilometers, punten, title, created_at) VALUES(?,?,?,?,?,?,?,?) '+
  'ON DUPLICATE KEY UPDATE aard=?, ploeg=?, kilometers=?, punten=?, title=?, updated_at=?;';

  const result = await connection.query(
    sql,
    [isoDate, rijksregisternummer, "Clubweekend", teamName, aantalKm, 10, title, new Date(),
      "Clubweekend", teamName, aantalKm, 10, title, new Date()
    ],
  );
  if (result[0].affectedRows < 1) {
    console.error(result)
    throw new Error(`database error`)
  }  
}



/**
 * @param {ParsedLine} filePath 
 */
const handleScanningRecord = async ({ niss, points, date: isoDate, ploeg, kilometers: aantalKm, title }) => {
  console.log({ niss, isoDate, title, aantalKm, teamName: `Groep ${ploeg}` });
  await insertActiviteitenRecord({ niss, isoDate, title, aantalKm, teamName: `Groep ${ploeg}` });
}


(async () => {
  const unhandledRecords = [];
  connection = await mysql.createConnection(databaseConfigs)
  await connection.connect();
  console.log("Connected!");
  await connection.beginTransaction();
  try {
    const filesList = listFilesInDirMatchingFilter(directoryPath, filter);
    console.log(filesList);
    for (const file of filesList) {
      const fileContent = parseFile(file);
      for (const parsedLine of fileContent) {
        try {
          await handleScanningRecord(parsedLine);
        } catch (error) {
          console.error(error);
          unhandledRecords.push(parsedLine);
        }
      }


    }
    fs.writeFileSync("unhandledRecords.txt", unhandledRecords.map(r => [r.name, r.points].join(',')).join('\n'));
    await connection.commit()
    // await connection.rollback();
  } catch (e) {
    console.error("Unexpected error ocurred", e);
    await connection.rollback();
  }

  await connection.end()
})();
