import { GameState } from "../interfaces/GameState";

const initialState: GameState = {
  apiStatus: "idle",
  data: {
    msg: "",
    referenceCode: "",
    success: false,
    data: {
      contextVars: { context: "", contextKey: "" },
      currentOrders: [],
      territories: {},
      territoryStatuses: [],
      units: {},
    },
  },
  error: null,
  order: {
    inProgress: false,
  },
  overview: {
    alternatives: "",
    anon: "Yes",
    drawType: "draw-votes-public",
    excusedMissedTurns: 4,
    gameID: 0,
    gameOver: "No",
    members: [],
    minimumBet: 5,
    name: "",
    pauseTimeRemaining: null,
    phase: "",
    phaseMinutes: 14400,
    playerTypes: "",
    pot: 35,
    potType: "",
    processStatus: "",
    processTime: null,
    season: "Spring",
    startTime: 0,
    turn: 0,
    user: {
      member: {
        bet: 5,
        country: "Russia",
        countryID: 1,
        excusedMissedTurns: 0,
        missedPhases: 0,
        newMessagesFrom: [],
        online: false,
        orderStatus: {},
        status: "Playing",
        supplyCenterNo: 4,
        timeLoggedIn: 0,
        unitNo: 4,
        userID: 1,
        username: "",
        votes: [],
      },
    },
    variant: {
      id: 1,
      mapID: 1,
      name: "Classic",
      fullName: "Classic",
      description: "The standard Diplomacy map of Europe.",
      author: "Avalon Hill",
      countries: [],
      variantClasses: {
        drawMap: "Classic",
        adjudicatorPreGame: "Classic",
      },
      codeVersion: null,
      cacheVersion: null,
      coastParentIDByChildID: {
        "76": 8,
        "77": 8,
        "78": 32,
        "79": 32,
        "80": 20,
        "81": 20,
      },
      coastChildIDsByParentID: {
        "8": [76, 77],
        "32": [78, 79],
        "20": [80, 81],
      },
      terrIDByName: null,
      supplyCenterCount: 34,
      supplyCenterTarget: 18,
    },
    variantID: 1,
    year: 1901,
  },
  status: {
    gameID: 2,
    countryID: 0,
    variantID: 1,
    potType: "",
    turn: 0,
    phase: "",
    gameOver: "No",
    pressType: "",
    phases: [],
    standoffs: [],
    occupiedFrom: [],
    votes: null,
    orderStatus: "",
    status: "",
  },
};

export default initialState;
