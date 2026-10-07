# VS2.3 owner test

Staging: https://v2quote.vietnamtraveladvisor.com.vn. Use ADMIN; all new write permissions initially ADMIN only.

1. Open the STAGING TEST VS23 PRIVATE example (8 paying pax; hotel 4★, cruise 4★). Open Smart Costing and Pricing Matrix. Compare default five inclusive bands and calculation scenarios. Check vehicle/guide counts and VND source costs.
2. Compare PRIVATE/SIC star variants and Custom Mix over the same itinerary. Open B2B policy, selling price, commission/net and FX; compare B2C family example with its confirmed service populations and no agent commission.
3. Open the below-margin example: Validate must block MARGIN_BELOW_POLICY. Review recorded manager override reason/actor/time and exact context. No automatic price increase.
4. In an editable revision, generate → select/recommend cells → validate → lock. Preview Visual Proposal; check frozen matrix, selected hotel/cruise and terms. Approve → Send. Matrix and quote become read-only; Create Revision makes a new editable version and leaves issued proposal intact.
5. Choose an offered cell in the issued channel, enter actual paying pax and optional confirmed guest composition. Recheck Exact Pax. A below-policy result must require its own override. Accept exact selection → create Booking. Confirm exact guests/price/FX/terms/option in frozen booking.
6. Open booking Sales Handover: review commitment and service sheet, complete checklist (or explicitly mark flight not provided), classify MUST_MATCH/flexible requirements, prepare → submit.
7. Operations Center → Sales Handover Intake → New. Return with reason, correct/resubmit, then Accept. Verify READY_TO_BOOK without requiring supplier confirmations/payments.
8. Add a change request; review → approve/reject → implemented (status record only). Confirm original booking snapshot unchanged.
9. Test a cost-denied account: selling-only matrix/quote and safe handover summary; supplier costs and policy/scenario endpoints denied. Sales/Operations accounts cannot perform newly ADMIN-only actions.

All fixture supplier amounts and terms are synthetic. No real customer messages or payments are sent by this workflow. Record owner PASS/issues before requesting PR merge.
