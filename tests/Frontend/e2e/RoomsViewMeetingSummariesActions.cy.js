import { interceptIndefinitely } from "../support/utils/interceptIndefinitely.js";

describe("Rooms view meeting summaries actions", function () {
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

  it("download meeting summary", function () {
    const downloadRequest = interceptIndefinitely(
      "GET",
      "api/v1/rooms/abc-def-123/meetingSummaries/2/download*",
      {
        statusCode: 200,
        headers: {
          "content-type": "text/markdown; charset=utf-8",
          "content-disposition":
            "attachment; filename=meeting-summary-abc-def-123-2022-08-16-1000.md",
        },
        body: "# Summary\n\n- Item\n",
      },
      "downloadRequest",
    );

    cy.visit("/rooms/abc-def-123#tab=meeting_summaries");
    cy.wait("@roomMeetingSummariesRequest");

    cy.get('[data-test="room-meeting-summary-item"]')
      .eq(1)
      .find('[data-test="room-meeting-summaries-download-button"]')
      .should(
        "have.attr",
        "aria-label",
        'rooms.meeting_summaries.download_aria_{"date":"08/16/2022, 10:00"}',
      )
      .click();

    // Check loading
    cy.get('[data-test="room-meeting-summary-item"]')
      .eq(1)
      .find('[data-test="room-meeting-summaries-download-button"]')
      .should("be.disabled")
      .then(() => {
        downloadRequest.sendResponse();
      });

    cy.wait("@downloadRequest");

    cy.readFile(
      "tests/Frontend/downloads/meeting-summary-abc-def-123-2022-08-16-1000.md",
    ).should("eq", "# Summary\n\n- Item\n");

    cy.get('[data-test="room-meeting-summary-item"]')
      .eq(1)
      .find('[data-test="room-meeting-summaries-download-button"]')
      .should("not.be.disabled");
  });

  it("download meeting summary errors", function () {
    cy.visit("/rooms/abc-def-123#tab=meeting_summaries");
    cy.wait("@roomMeetingSummariesRequest");

    // Summary was deleted in the meantime
    cy.intercept(
      "GET",
      "api/v1/rooms/abc-def-123/meetingSummaries/2/download*",
      {
        statusCode: 404,
        body: {
          message: "model_not_found",
          model: "meeting_summary",
          ids: [2],
        },
      },
    ).as("downloadRequest");

    cy.fixture("roomMeetingSummaries.json").then((summaries) => {
      summaries.data = summaries.data.slice(0, 1);
      summaries.meta.to = 1;
      summaries.meta.total = 1;
      summaries.meta.total_no_filter = 1;

      cy.intercept("GET", "api/v1/rooms/abc-def-123/meetingSummaries*", {
        statusCode: 200,
        body: summaries,
      }).as("roomMeetingSummariesRequest");
    });

    cy.get('[data-test="room-meeting-summary-item"]')
      .eq(1)
      .find('[data-test="room-meeting-summaries-download-button"]')
      .click();

    cy.wait("@downloadRequest");
    cy.wait("@roomMeetingSummariesRequest");

    cy.checkToastMessage("rooms.meeting_summaries.gone");
    cy.get('[data-test="room-meeting-summary-item"]').should("have.length", 1);

    // Server error
    cy.fixture("roomMeetingSummaries.json").then((summaries) => {
      cy.intercept("GET", "api/v1/rooms/abc-def-123/meetingSummaries*", {
        statusCode: 200,
        body: summaries,
      }).as("roomMeetingSummariesRequest");
    });
    cy.get('[data-test="room-meeting-summaries-reload-button"]').click();
    cy.wait("@roomMeetingSummariesRequest");

    cy.intercept(
      "GET",
      "api/v1/rooms/abc-def-123/meetingSummaries/2/download*",
      {
        statusCode: 500,
        body: {
          message: "Test",
        },
      },
    ).as("downloadRequest");

    cy.get('[data-test="room-meeting-summary-item"]')
      .eq(1)
      .find('[data-test="room-meeting-summaries-download-button"]')
      .click();

    cy.wait("@downloadRequest");

    cy.checkToastMessage([
      'app.flash.server_error.message_{"message":"Test"}',
      'app.flash.server_error.error_code_{"statusCode":500}',
    ]);
  });

  it("edit meeting summary access", function () {
    cy.visit("/rooms/abc-def-123#tab=meeting_summaries");
    cy.wait("@roomMeetingSummariesRequest");

    cy.get('[data-test="room-meeting-summaries-edit-dialog"]').should(
      "not.exist",
    );
    cy.get('[data-test="room-meeting-summary-item"]')
      .eq(0)
      .find('[data-test="room-meeting-summaries-edit-button"]')
      .click();

    const editRequest = interceptIndefinitely(
      "PUT",
      "api/v1/rooms/abc-def-123/meetingSummaries/1",
      {
        statusCode: 200,
        body: { data: {} },
      },
      "editRequest",
    );

    cy.fixture("roomMeetingSummaries.json").then((summaries) => {
      summaries.data[0].access = 2;

      cy.intercept("GET", "api/v1/rooms/abc-def-123/meetingSummaries*", {
        statusCode: 200,
        body: summaries,
      }).as("roomMeetingSummariesRequest");
    });

    cy.get('[data-test="room-meeting-summaries-edit-dialog"]')
      .should("be.visible")
      .and("include.text", "rooms.meeting_summaries.modals.edit.title")
      .within(() => {
        cy.get('[data-test="access-field"]')
          .should("include.text", "rooms.recordings.access")
          .within(() => {
            cy.get("#summary-access-3").should("be.checked");
            cy.get('[data-test="access-2-field"]')
              .should("include.text", "rooms.recordings.access_types.moderator")
              .find("#summary-access-2")
              .click();
          });

        cy.get('[data-test="dialog-save-button"]').click();

        // Check loading
        cy.get('[data-test="dialog-cancel-button"]')
          .should("be.disabled")
          .then(() => {
            editRequest.sendResponse();
          });
      });

    cy.wait("@editRequest").then((interception) => {
      expect(interception.request.body).to.eql({ access: 2 });
    });
    cy.wait("@roomMeetingSummariesRequest");

    cy.get('[data-test="room-meeting-summaries-edit-dialog"]').should(
      "not.exist",
    );
    cy.get('[data-test="room-meeting-summary-item"]')
      .eq(0)
      .should("include.text", "rooms.recordings.access_types.moderator");
  });

  it("edit meeting summary access errors", function () {
    cy.visit("/rooms/abc-def-123#tab=meeting_summaries");
    cy.wait("@roomMeetingSummariesRequest");

    cy.get('[data-test="room-meeting-summary-item"]')
      .eq(0)
      .find('[data-test="room-meeting-summaries-edit-button"]')
      .click();

    // Validation error
    cy.intercept("PUT", "api/v1/rooms/abc-def-123/meetingSummaries/1", {
      statusCode: 422,
      body: {
        message: "The access field is required.",
        errors: {
          access: ["The access field is required."],
        },
      },
    }).as("editRequest");

    cy.get('[data-test="room-meeting-summaries-edit-dialog"]')
      .find('[data-test="dialog-save-button"]')
      .click();
    cy.wait("@editRequest");

    cy.get('[data-test="room-meeting-summaries-edit-dialog"]')
      .should("be.visible")
      .and("include.text", "The access field is required.");

    // Summary was deleted in the meantime
    cy.intercept("PUT", "api/v1/rooms/abc-def-123/meetingSummaries/1", {
      statusCode: 404,
      body: {
        message: "model_not_found",
        model: "meeting_summary",
        ids: [1],
      },
    }).as("editRequest");

    cy.fixture("roomMeetingSummaries.json").then((summaries) => {
      summaries.data = summaries.data.slice(1, 2);
      summaries.meta.to = 1;
      summaries.meta.total = 1;
      summaries.meta.total_no_filter = 1;

      cy.intercept("GET", "api/v1/rooms/abc-def-123/meetingSummaries*", {
        statusCode: 200,
        body: summaries,
      }).as("roomMeetingSummariesRequest");
    });

    cy.get('[data-test="room-meeting-summaries-edit-dialog"]')
      .find('[data-test="dialog-save-button"]')
      .click();
    cy.wait("@editRequest");
    cy.wait("@roomMeetingSummariesRequest");

    cy.checkToastMessage("rooms.meeting_summaries.gone");
    cy.get('[data-test="room-meeting-summaries-edit-dialog"]').should(
      "not.exist",
    );
    cy.get('[data-test="room-meeting-summary-item"]').should("have.length", 1);
  });

  it("delete meeting summary", function () {
    cy.visit("/rooms/abc-def-123#tab=meeting_summaries");
    cy.wait("@roomMeetingSummariesRequest");

    cy.get('[data-test="room-meeting-summaries-delete-dialog"]').should(
      "not.exist",
    );
    cy.get('[data-test="room-meeting-summary-item"]')
      .eq(0)
      .find('[data-test="room-meeting-summaries-delete-button"]')
      .click();
    cy.get('[data-test="room-meeting-summaries-delete-dialog"]')
      .should("be.visible")
      .and("include.text", "rooms.meeting_summaries.modals.delete.title")
      .and("include.text", "rooms.meeting_summaries.modals.delete.confirm");

    const deleteRequest = interceptIndefinitely(
      "DELETE",
      "api/v1/rooms/abc-def-123/meetingSummaries/1",
      {
        statusCode: 204,
      },
      "deleteRequest",
    );

    cy.fixture("roomMeetingSummaries.json").then((summaries) => {
      summaries.data = summaries.data.slice(1, 2);
      summaries.meta.to = 1;
      summaries.meta.total = 1;
      summaries.meta.total_no_filter = 1;

      cy.intercept("GET", "api/v1/rooms/abc-def-123/meetingSummaries*", {
        statusCode: 200,
        body: summaries,
      }).as("roomMeetingSummariesRequest");
    });

    cy.get('[data-test="room-meeting-summaries-delete-dialog"]')
      .find('[data-test="dialog-continue-button"]')
      .should("have.text", "app.yes")
      .click();

    // Check loading
    cy.get('[data-test="room-meeting-summaries-delete-dialog"]')
      .find('[data-test="dialog-cancel-button"]')
      .should("have.text", "app.no")
      .should("be.disabled")
      .then(() => {
        deleteRequest.sendResponse();
      });

    cy.wait("@deleteRequest");
    cy.wait("@roomMeetingSummariesRequest");

    cy.get('[data-test="room-meeting-summary-item"]').should("have.length", 1);
    cy.get('[data-test="room-meeting-summaries-delete-dialog"]').should(
      "not.exist",
    );
  });

  it("delete meeting summary errors", function () {
    cy.visit("/rooms/abc-def-123#tab=meeting_summaries");
    cy.wait("@roomMeetingSummariesRequest");

    cy.get('[data-test="room-meeting-summary-item"]')
      .eq(0)
      .find('[data-test="room-meeting-summaries-delete-button"]')
      .click();

    // Server error
    cy.intercept("DELETE", "api/v1/rooms/abc-def-123/meetingSummaries/1", {
      statusCode: 500,
      body: {
        message: "Test",
      },
    }).as("deleteRequest");

    cy.get('[data-test="room-meeting-summaries-delete-dialog"]')
      .find('[data-test="dialog-continue-button"]')
      .click();
    cy.wait("@deleteRequest");

    cy.checkToastMessage([
      'app.flash.server_error.message_{"message":"Test"}',
      'app.flash.server_error.error_code_{"statusCode":500}',
    ]);
    cy.get('[data-test="room-meeting-summaries-delete-dialog"]').should(
      "be.visible",
    );

    // Summary was deleted in the meantime
    cy.intercept("DELETE", "api/v1/rooms/abc-def-123/meetingSummaries/1", {
      statusCode: 404,
      body: {
        message: "model_not_found",
        model: "meeting_summary",
        ids: [1],
      },
    }).as("deleteRequest");

    cy.fixture("roomMeetingSummaries.json").then((summaries) => {
      summaries.data = summaries.data.slice(1, 2);
      summaries.meta.to = 1;
      summaries.meta.total = 1;
      summaries.meta.total_no_filter = 1;

      cy.intercept("GET", "api/v1/rooms/abc-def-123/meetingSummaries*", {
        statusCode: 200,
        body: summaries,
      }).as("roomMeetingSummariesRequest");
    });

    cy.get('[data-test="room-meeting-summaries-delete-dialog"]')
      .find('[data-test="dialog-continue-button"]')
      .click();
    cy.wait("@deleteRequest");
    cy.wait("@roomMeetingSummariesRequest");

    cy.checkToastMessage("rooms.meeting_summaries.gone");
    cy.get('[data-test="room-meeting-summaries-delete-dialog"]').should(
      "not.exist",
    );
    cy.get('[data-test="room-meeting-summary-item"]').should("have.length", 1);
  });
});
