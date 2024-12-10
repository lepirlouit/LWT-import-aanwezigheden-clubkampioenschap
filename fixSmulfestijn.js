require('dotenv').config();
const path = require('node:path');
const fs = require('node:fs');
const directoryPath = './';
const filter = /PuntenSmulfestijn2024.csv/;
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
    const [name, pointsStr] = line.split(',');
    return({name, points: parseInt(pointsStr)});
  });
}

const updateAanwezigheidRecord = async ({ niss, points })  => {
  const sql = `UPDATE aanwezigheden 
  SET Andere = Andere - 1,
    SMF = ?
  WHERE rijksregisternummer = ?;`;
  const price = points / 20.0;
  const result = await connection.query(
    sql, [
      points/10,
      niss,
  ]);
  if (result[0].affectedRows !== 1) {
    throw new Error(`User ${JSON.stringify(aanwezigheid)} not found in our database`)
  }  
}

const getNiss = async (name) => {
  const splittedName = name.split(' ');
  const firstLastname = [splittedName.pop(), ...splittedName].join(' ');
  // console.log("search for", firstLastname);
  const sql = "select a.rijksregisternummer from aanwezigheden a where lower(a.Naam) like lower(?)";
  
  const result = await connection.query(
    sql,
    [firstLastname],
  );

  // console.log(result)
  if (result[0][0]) {
    return result[0][0].rijksregisternummer;
  }
  throw new Error("Unable to get Niss");
}

/**
 * @param {ParsedLine} filePath 
 */
const handleScanningRecord = async ({ name, points }) => {
  const niss = await getNiss(name);
  console.log(name, niss, points);
  await updateAanwezigheidRecord({niss, points});
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
    // await connection.commit()
    await connection.rollback();
  } catch (e) {
    console.error("Unexpected error ocurred", e);
    await connection.rollback();
  }

  await connection.end()
})();
