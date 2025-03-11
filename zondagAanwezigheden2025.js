require('dotenv').config();
const path = require('node:path');
const fs = require('node:fs');
const directoryPath = './scanners/';
const filter = /20250309.*AANW.*\.TXT/;
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
 * @typedef {Object} parsedLine
 * @property {string} date - The date
 * @property {string} niss - The national security number
 * @property {number} teamNumber - The national security number
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
    const [date, niss, teamNumberStr] = line.split(',');
    return({date, niss, teamNumber: parseInt(teamNumberStr)});
  });
}

const insertActiviteitenRecord = async ({ niss, isoDate, sundayNumber, aantalKm, teamName })  => {
  const rijksregisternummer = niss;
  const sql = 'INSERT INTO activiteiten (datum, rijksregisternummer, aard, ploeg, kilometers, punten, title, created_at) VALUES(?,?,?,?,?,?,?,?) '+
  'ON DUPLICATE KEY UPDATE aard=?, ploeg=?, kilometers=?, punten=?, title=?, updated_at=?;';

  const result = await connection.query(
    sql,
    [isoDate, rijksregisternummer, "zondagrit", teamName, aantalKm, 10, `Z${sundayNumber}`, new Date(),
      "zondagrit", teamName, aantalKm, 10, `Z${sundayNumber}`, new Date()
    ],
  );
  if (result[0].affectedRows !== 1) {
    throw new Error(`User ${JSON.stringify(aanwezigheid)} not found in our database`)
  }  
}

/**
 * @param {string} date 
 * @returns {number}
 */
const getSundayNumber = (date) => {
  const day = new Date(date.split("/").reverse().join("-"));
  return `${Math.floor(((day - new Date(2025, 0, 1)) / (24 * 60 * 60 * 1000 * 7)) - 7)}`.padStart(2, '0');;
};

/**
 * 
 * @param {number} teamNumber 
 * @returns {string}
 */
const getTeamName = (teamNumber) => {
  if (teamNumber === 1) {
    return 'A-ploeg'
  }
  if (teamNumber === 3) {
    return 'Tempo'
  }
  if (teamNumber === 4) {
    return 'Sportivo'
  }
  if (teamNumber === 5) {
    return 'Cyclo'
  }
  if (teamNumber === 6) {
    return 'Toeristen'
  }
  if (teamNumber === 7) {
    return 'D-ploeg'
  }
  if (teamNumber === 9) {
    return 'Trappers'
  }
  if (teamNumber === 10) {
    return 'Moderato'
  }
  throw new Error('Unhandled Team Number')
};

/**
 * 
 * @param {string} teamName 
 * @param {string} date - format yyyy-mm-dd 
 */
const getAantalKm = async (teamName, date) => { 
  const sql = "select z.afstand from zondagritten z where z.datum = ? AND z.ploeg = ?";
  
  const result = await connection.query(
    sql,
    [date, teamName],
  );
  if (result[0][0]) {
    return result[0][0].afstand;
  }
  throw new Error("Unable to get Number of KM");
};

/**
 * @param {ParsedLine} filePath 
 */
const handleScanningRecord = async ({ date, niss, teamNumber }) => {
  const sundayNumber = getSundayNumber(date);
  const teamName = getTeamName(teamNumber);
  const isoDate = date.split('/').reverse().join('-');
  const aantalKm = await getAantalKm(teamName, isoDate);
  await insertActiviteitenRecord({niss, sundayNumber, aantalKm, teamName, isoDate});
  // await updateAanwezigheidRecord({niss, sundayNumber, aantalKm});
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
          // console.error(error);
          unhandledRecords.push(parsedLine);
        }
      }
    }
    fs.writeFileSync("unhandledRecords.txt", unhandledRecords.map(r => [r.date, r.niss, r.teamNumber].join(',')).join('\n'));
    await connection.commit()
    // await connection.rollback();
  } catch (e) {
    console.error("Unexpected error ocurred", e);
    await connection.rollback();
  }

  await connection.end()
})();
