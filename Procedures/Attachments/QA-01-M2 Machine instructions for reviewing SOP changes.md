# QA-01-M2: Machine instructions for reviewing SOP changes

1. <a id="1"></a>Always review and follow the most recent version of these instructions.

2. <a id="2"></a>Scope

   1. <a id="2.1"></a>Apply these instructions to every SOP and attachment changed in the pull request under review, plus every SOP related to one of them.

   2. <a id="2.2"></a>An SOP is related to a changed SOP if either one cites the other, or one of the other's attachments, anywhere in its text. Find related SOPs by searching `Procedures/` for each changed SOP's ID (for example "DV-02") and its attachments' IDs (for example "DV-02-M1").

   3. <a id="2.3"></a>If reviewing a related document shows that it needs a change too, bring it into scope.

3. <a id="3"></a>Compliance with QA-01

   1. <a id="3.1"></a>Check that each SOP's ID uses a category designator from [QA-01](../SOPs/QA-01%20Writing%20and%20maintaining%20Ex%20Machinis%20standard%20operating%20procedures.md) §5.2 and a two-digit number.

   2. <a id="3.2"></a>Check that each SOP has the Purpose, Roles and responsibilities, Required resources, General guidelines, Procedures, References and Attachments sections, in that order, per the SOP template ([QA-01-T1](QA-01-T1%20SOP%20template.md)).

   3. <a id="3.3"></a>Check that the General guidelines section says the SOP is formatted and maintained IAW QA-01.

   4. <a id="3.4"></a>Check that each attachment ID is its SOP's ID plus a designator from [QA-01](../SOPs/QA-01%20Writing%20and%20maintaining%20Ex%20Machinis%20standard%20operating%20procedures.md) §5.4, numbered in sequence.

   5. <a id="3.5"></a>Check that the References and Attachments sections list exactly the documents cited in the text, in alphabetical order by ID.

   6. <a id="3.6"></a>Check that every citation of an SOP or attachment ID is a working relative link, per [QA-01](../SOPs/QA-01%20Writing%20and%20maintaining%20Ex%20Machinis%20standard%20operating%20procedures.md) §5.9.

   7. <a id="3.7"></a>Run `python3 Procedures/Tools/update_anchors.py --check`. If it reports any file, run it without `--check`.

   8. <a id="3.8"></a>Check that [Procedures/README.md](../README.md) lists every SOP under its current title.

4. <a id="4"></a>Consistency

   1. <a id="4.1"></a>Check that numbered items are in sequence, with nothing skipped or repeated.

   2. <a id="4.2"></a>Check that every cross-reference to a section, SOP or attachment points to content that exists.

   3. <a id="4.3"></a>Check that no two documents give conflicting directions for the same situation, and that the SOPs agree with `CLAUDE.md`. Where they disagree, list the conflict in the summary; do not decide it.

   4. <a id="4.4"></a>Check that the same fact (a branch name, a file path, a URL, a role) is stated the same way everywhere.

5. <a id="5"></a>Scope of edits

   1. <a id="5.1"></a>Fix only what these instructions cover. Do not change the substance of a procedure; list anything that needs a judgment call in the summary for the project lead.

   2. <a id="5.2"></a>Commit the fixes to the pull request's branch with a message that names QA-01-M2.

6. <a id="6"></a>Provide a concise summary of activities performed and issues encountered when performing these instructions, including every document reviewed, every fix made and every question left for the project lead.
