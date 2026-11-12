import { interceptIndefinitely } from "../support/utils/interceptIndefinitely.js";

describe("Rooms view meeting summaries", function () {
  beforeEach(function () {
    cy.init();
    cy.interceptRoomViewRequests();

    cy.intercept("GET", "api/v1/rooms/abc-def-123/meetingSummaries*", {
      fixture: "roomMeetingSummaries.json",
    }).as("roomMeetingSummariesRequest");

    cy.fixture("config.json").then((config) => {
      config.data.general.base_url = Cypress.config("baseUrl");
      config.data.recap = { enabled: true };

      cy.intercept("GET", "api/v1/config", {
        statusCode: 200,
        body: config,
      });
    });

    cy.setValidRememberedParticipantName("Laura Rivera");
  });

  it("show tab", function () {
    // Feature enabled
    cy.visit("/rooms/abc-def-123");
    cy.wait("@roomRequest");
    cy.get("#tab-meeting_summaries").should("be.visible");

    // Feature disabled, room without summaries
    cy.fixture("config.json").then((config) => {
      config.data.general.base_url = Cypress.config("baseUrl");

      cy.intercept("GET", "api/v1/config", {
        statusCode: 200,
        body: config,
      });
    });
    cy.reload();
    cy.wait("@roomRequest");
    cy.get("#tab-recordings").should("be.visible");
    cy.get("#tab-meeting_summaries").should("not.exist");

    // Feature disabled, room with summaries
    cy.fixture("room.json").then((room) => {
      room.data.has_meeting_summaries = true;

      cy.intercept("GET", "api/v1/rooms/abc-def-123", {
        statusCode: 200,
        body: room,
      }).as("roomRequest");
    });
    cy.reload();
    cy.wait("@roomRequest");
    cy.get("#tab-meeting_summaries").should("be.visible");
  });

  it("load meeting summaries", function () {
    const roomMeetingSummariesRequest = interceptIndefinitely(
      "GET",
      "api/v1/rooms/abc-def-123/meetingSummaries*",
      { fixture: "roomMeetingSummaries.json" },
      "roomMeetingSummariesRequest",
    );

    cy.visit("/rooms/abc-def-123");

    cy.get("#tab-meeting_summaries").click();

    cy.url().should("include", "/rooms/abc-def-123#tab=meeting_summaries");

    // Check loading
    cy.get('[data-test="overlay"]').should("be.visible");
    cy.get('[data-test="room-meeting-summaries-reload-button"]')
      .should("be.disabled")
      .then(() => {
        roomMeetingSummariesRequest.sendResponse();
      });

    cy.wait("@roomMeetingSummariesRequest").then((interception) => {
      expect(interception.request.query).to.contain({
        page: "1",
        sort_direction: "desc",
      });
    });

    cy.get('[data-test="overlay"]').should("not.exist");
    cy.get('[data-test="room-meeting-summaries-reload-button"]').should(
      "not.be.disabled",
    );

    cy.get('[data-test="room-meeting-summary-item"]').should("have.length", 2);

    // Summary as a link to a document
    cy.get('[data-test="room-meeting-summary-item"]')
      .eq(0)
      .should("include.text", "08/17/2022, 11:20")
      .and("include.text", "20 app.time_formats.minutes")
      .and("include.text", "rooms.recordings.access_types.owner")
      .within(() => {
        cy.get('[data-test="room-meeting-summary-document-hint"]').should(
          "have.text",
          "rooms.meeting_summaries.document_hint_owner",
        );
        cy.get('a[data-test="room-meeting-summaries-open-document-button"]')
          .should(
            "have.attr",
            "href",
            "https://docs.example.com/docs/0b6b7c7e-5d3c-4c1e-9a43-3f0c2f6d9a11/",
          )
          .and("have.attr", "target", "_blank")
          .and("have.attr", "rel", "noopener noreferrer");
        cy.get('[data-test="room-meeting-summaries-download-button"]').should(
          "not.exist",
        );
        cy.get('[data-test="room-meeting-summaries-edit-button"]').should(
          "be.visible",
        );
        cy.get('[data-test="room-meeting-summaries-delete-button"]').should(
          "be.visible",
        );
      });

    // Summary text stored in the room
    cy.get('[data-test="room-meeting-summary-item"]')
      .eq(1)
      .should("include.text", "08/16/2022, 10:00")
      .and(
        "include.text",
        "1 app.time_formats.hour, 0 app.time_formats.minutes",
      )
      .and("include.text", "rooms.recordings.access_types.participant")
      .within(() => {
        cy.get('[data-test="room-meeting-summary-document-hint"]').should(
          "not.exist",
        );
        cy.get(
          '[data-test="room-meeting-summaries-open-document-button"]',
        ).should("not.exist");
        cy.get('[data-test="room-meeting-summaries-download-button"]').should(
          "be.visible",
        );
      });

    cy.get('[data-test="retention-period-message"]')
      .should("include.text", "rooms.meeting_summaries.retention_period.title")
      .and(
        "include.text",
        "rooms.meeting_summaries.retention_period.unlimited",
      );

    // Sort ascending
    cy.get('[data-test="room-meeting-summaries-sort-button"]').click();
    cy.wait("@roomMeetingSummariesRequest").then((interception) => {
      expect(interception.request.query).to.contain({
        page: "1",
        sort_direction: "asc",
      });
    });
  });

  it("load meeting summaries empty", function () {
    cy.intercept("GET", "api/v1/rooms/abc-def-123/meetingSummaries*", {
      statusCode: 200,
      body: {
        data: [],
        meta: {
          current_page: 1,
          from: null,
          last_page: 1,
          per_page: 10,
          to: null,
          total: 0,
          total_no_filter: 0,
        },
      },
    }).as("roomMeetingSummariesRequest");

    cy.visit("/rooms/abc-def-123#tab=meeting_summaries");
    cy.wait("@roomMeetingSummariesRequest");

    cy.get('[data-test="room-meeting-summary-item"]').should("not.exist");
    cy.contains("rooms.meeting_summaries.nodata").should("be.visible");
  });

  it("load meeting summaries as room member", function () {
    cy.fixture("room.json").then((room) => {
      room.data.owner = { id: 2, name: "Max Doe" };
      room.data.is_member = true;

      cy.intercept("GET", "api/v1/rooms/abc-def-123", {
        statusCode: 200,
        body: room,
      }).as("roomRequest");
    });

    cy.visit("/rooms/abc-def-123#tab=meeting_summaries");
    cy.wait("@roomMeetingSummariesRequest");

    cy.get('[data-test="filter-dropdown"]').should("not.exist");

    cy.get('[data-test="room-meeting-summary-item"]')
      .eq(0)
      .should("not.include.text", "rooms.recordings.access_types.owner")
      .within(() => {
        cy.get('[data-test="room-meeting-summary-document-hint"]').should(
          "have.text",
          "rooms.meeting_summaries.document_hint",
        );
        cy.get('[data-test="room-meeting-summaries-edit-button"]').should(
          "not.exist",
        );
        cy.get('[data-test="room-meeting-summaries-delete-button"]').should(
          "not.exist",
        );
      });
  });

  it("filter meeting summaries", function () {
    cy.visit("/rooms/abc-def-123#tab=meeting_summaries");
    cy.wait("@roomMeetingSummariesRequest");

    cy.get('[data-test="filter-dropdown-items"]').should("not.exist");
    cy.get('[data-test="filter-dropdown"]')
      .should("have.text", "rooms.recordings.filter.all")
      .click();

    cy.get('[data-test="filter-dropdown-items"]')
      .should("be.visible")
      .within(() => {
        cy.get("[data-test=filter-dropdown-option]").should("have.length", 5);
        cy.get("[data-test=filter-dropdown-option]")
          .eq(3)
          .should("have.text", "rooms.recordings.filter.moderator_access")
          .click();
      });

    cy.wait("@roomMeetingSummariesRequest").then((interception) => {
      expect(interception.request.query).to.contain({
        page: "1",
        filter: "moderator_access",
      });
    });
  });

  it("load meeting summaries errors", function () {
    cy.intercept("GET", "api/v1/rooms/abc-def-123/meetingSummaries*", {
      statusCode: 500,
      body: {
        message: "Test",
      },
    }).as("roomMeetingSummariesRequest");

    cy.visit("/rooms/abc-def-123#tab=meeting_summaries");
    cy.wait("@roomMeetingSummariesRequest");

    cy.checkToastMessage([
      'app.flash.server_error.message_{"message":"Test"}',
      'app.flash.server_error.error_code_{"statusCode":500}',
    ]);

    cy.get('[data-test="room-meeting-summary-item"]').should("not.exist");

    cy.intercept("GET", "api/v1/rooms/abc-def-123/meetingSummaries*", {
      fixture: "roomMeetingSummaries.json",
    }).as("roomMeetingSummariesRequest");

    cy.get('[data-test="loading-retry-button"]')
      .should("be.visible")
      .and("have.text", "app.reload")
      .click();

    cy.wait("@roomMeetingSummariesRequest");
    cy.get('[data-test="room-meeting-summary-item"]').should("have.length", 2);
  });
});
