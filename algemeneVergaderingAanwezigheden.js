require('dotenv').config();
const path = require('node:path');
const fs = require('node:fs');
const directoryPath = './scanners/';
const filter = /20241207AANW_Leden_Vergadering.*\.TXT/;
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

const updateAanwezigheidRecord = async ({ niss })  => {
  const rijksregisternummer = niss;
  const sql = `UPDATE aanwezigheden SET Andere = Andere + 1, punten = REPLACE(CAST(CAST(replace(punten, ',', '.') AS DECIMAL(4)) + 10 AS CHAR), '.', ','), bedrag2022 = REPLACE(CAST(CAST(replace(bedrag2022, ',', '.') AS DECIMAL(4,1)) + 0.5 AS CHAR), '.', ','), totaalbedrag = REPLACE(CAST(CAST(replace(totaalbedrag, ',', '.') AS DECIMAL(4,1)) + 0.5 AS CHAR), '.', ','), totaalpunten = REPLACE(CAST(CAST(replace(totaalpunten, ',', '.') AS DECIMAL(4)) + 10 AS CHAR), '.', ','), aanweztotaal = REPLACE(CAST(CAST(replace(aanweztotaal, ',', '.') AS DECIMAL(4)) + 1 AS CHAR), '.', ',') WHERE rijksregisternummer = ?;`;

  const result = await connection.query(
    sql,
    [rijksregisternummer],
  );
  if (result[0].affectedRows !== 1) {
    throw new Error(`User ${JSON.stringify(aanwezigheid)} not found in our database`)
  }  
}



/**
 * @param {ParsedLine} filePath 
 */
const handleScanningRecord = async ({ date, niss, teamNumber }) => {
  await updateAanwezigheidRecord({niss});
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
