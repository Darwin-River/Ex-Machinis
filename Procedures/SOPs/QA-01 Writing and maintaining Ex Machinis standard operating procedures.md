# QA-01: Writing and maintaining Ex Machinis standard operating procedures

1. # <a id="1"></a>Purpose

   1. <a id="1.1"></a>This standard operating procedure (SOP) sets out how Ex Machinis SOPs are written, numbered, formatted and kept up to date.

   2. <a id="1.2"></a>SOPs capture what the project has learned about running itself: how developers and Claude change the game, test it, explain it, promote it and pay for it. Update an SOP whenever a process changes, in the same pull request as the change, so the improvement is locked in.

2. # <a id="2"></a>Roles and responsibilities

   1. <a id="2.1"></a>The project lead is responsible for approving every new or changed SOP and for keeping the set of SOPs complete.

   2. <a id="2.2"></a>Developers and Claude are responsible for proposing SOP changes, through an issue per [AD-01](AD-01%20Creating%20and%20resolving%20GitHub%20issues.md), whenever they find a procedure that is missing, wrong or slower than it needs to be.

   3. <a id="2.3"></a>Claude is responsible for applying the "Machine instructions for reviewing SOP changes" ([QA-01-M2](../Attachments/QA-01-M2%20Machine%20instructions%20for%20reviewing%20SOP%20changes.md)) to every pull request that changes an SOP or attachment.

3. # <a id="3"></a>Required resources

   1. <a id="3.1"></a>Python 3, to run the anchor routine, [update_anchors.py](../Tools/update_anchors.py).

4. # <a id="4"></a>General guidelines

   1. <a id="4.1"></a>This SOP will be formatted and maintained IAW QA-01.

   2. <a id="4.2"></a>Write SOPs in plain language. Say who does what, in what order, and where the result goes. Prefer short numbered steps to long paragraphs.

   3. <a id="4.3"></a>`CLAUDE.md` at the root of the repository is the short version of these SOPs that every Claude session reads first. When an SOP change affects a rule summarized there, update `CLAUDE.md` in the same pull request so the two never disagree.

5. # <a id="5"></a>Procedures

   1. <a id="5.1"></a>Give each SOP a unique ID: a two-letter category designator, a hyphen, and a two-digit number that distinguishes SOPs in the same category.

   2. <a id="5.2"></a>Use the following categories:

      1. <a id="5.2.1"></a>**Quality Assurance (QA)** SOPs keep the project's code, documents and SOPs correct and consistent.

      2. <a id="5.2.2"></a>**Administration (AD)** SOPs cover the repository itself: issues, branches, pull requests and merging.

      3. <a id="5.2.3"></a>**Development (DV)** SOPs cover changing, testing, documenting and playtesting the game.

      4. <a id="5.2.4"></a>**Outreach (OR)** SOPs cover the blog, social media, and promoting Ex Machinis to schools, teachers, students and the general public.

      5. <a id="5.2.5"></a>**Fundraising (FR)** SOPs cover the Ko-fi support page and any other way the project raises money.

   3. <a id="5.3"></a>Store every SOP in `Procedures/SOPs`.

   4. <a id="5.4"></a>SOPs are often accompanied by attachments. Give each attachment an ID made of its SOP's ID, a hyphen, and a sequentially-assigned designator:

      1. <a id="5.4.1"></a>**Appendices (A)** hold supplementary information, such as checklists and reference lists.

      2. <a id="5.4.2"></a>**Data (D)** attachments are spreadsheets or documents that store data related to the SOP.

      3. <a id="5.4.3"></a>**Forms (F)** are filled in to record progress on an SOP's tasks.

      4. <a id="5.4.4"></a>**Machine instructions (M)** are directions that can be given to an AI agent, such as Claude, to carry out a task.

      5. <a id="5.4.5"></a>**Templates (T)** are documents used to create other documents.

      6. <a id="5.4.6"></a>**Videos (V)** are short clips that show a process.

   5. <a id="5.5"></a>Store every attachment in `Procedures/Attachments`.

   6. <a id="5.6"></a>All SOPs use the format of this one. An SOP may add sections where the procedure needs them, but it keeps the required sections in the template's order. To bring an SOP into this format, direct Claude to apply the "Machine instructions for proofreading SOPs" ([QA-01-M1](../Attachments/QA-01-M1%20Machine%20instructions%20for%20proofreading%20SOPs.md)) to it.

   7. <a id="5.7"></a>To write a new SOP, start from the SOP template ([QA-01-T1](../Attachments/QA-01-T1%20SOP%20template.md)) and replace every instruction in curly brackets. Name the file with the SOP's ID and title. To write new machine instructions, start from the machine instructions template ([QA-01-T2](../Attachments/QA-01-T2%20Machine%20instructions%20template.md)).

   8. <a id="5.8"></a>Make sure every document listed in an SOP's References and Attachments sections is cited in its text, and every document cited in its text is listed there.

   9. <a id="5.9"></a>Write every citation of an SOP or attachment ID, including those in the References and Attachments sections, as a Markdown link to that document's file, using a path relative to the citing document with each space written as `%20`, so it opens directly on GitHub. A document does not need to link to itself.

   10. <a id="5.10"></a>Give every numbered item a hidden link anchor named by its full section number, for example `5. # <a id="5"></a>Procedures` and `   2. <a id="5.2"></a>Use the following…`. A link ending in `#5.2` then opens the document at that item. Do not write anchors by hand: run `python3 Procedures/Tools/update_anchors.py` after every edit that adds, removes or renumbers an item. `python3 Procedures/Tools/update_anchors.py --check` lists any file whose anchors are out of date.

   11. <a id="5.11"></a>Change SOPs the same way as any other part of the repository: through an issue and a pull request into `main`, per [AD-01](AD-01%20Creating%20and%20resolving%20GitHub%20issues.md) and [AD-02](AD-02%20Working%20with%20branches%20and%20pull%20requests.md). Before the pull request is merged, Claude applies [QA-01-M2](../Attachments/QA-01-M2%20Machine%20instructions%20for%20reviewing%20SOP%20changes.md) to it, and the project lead reviews the changes and Claude's summary.

   12. <a id="5.12"></a>Keep [Procedures/README.md](../README.md), the index of SOPs, up to date whenever an SOP is added, renamed or retired.

6. # <a id="6"></a>References

   1. <a id="6.1"></a>[AD-01](AD-01%20Creating%20and%20resolving%20GitHub%20issues.md): Creating and resolving GitHub issues

   2. <a id="6.2"></a>[AD-02](AD-02%20Working%20with%20branches%20and%20pull%20requests.md): Working with branches and pull requests

7. # <a id="7"></a>Attachments

   1. <a id="7.1"></a>[QA-01-M1](../Attachments/QA-01-M1%20Machine%20instructions%20for%20proofreading%20SOPs.md): Machine instructions for proofreading SOPs

   2. <a id="7.2"></a>[QA-01-M2](../Attachments/QA-01-M2%20Machine%20instructions%20for%20reviewing%20SOP%20changes.md): Machine instructions for reviewing SOP changes

   3. <a id="7.3"></a>[QA-01-T1](../Attachments/QA-01-T1%20SOP%20template.md): SOP template

   4. <a id="7.4"></a>[QA-01-T2](../Attachments/QA-01-T2%20Machine%20instructions%20template.md): Machine instructions template
