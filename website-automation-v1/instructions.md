# Website Automation V1

## Introduction
- The main idea behind this project is to create a skill that can easily make a website using a backend model.
- When I write "(YOUR ATTENTION: [...])" it means that I am talking to YOU and you should focus on what is written inside [...]
- This is just an idea of what I want
- Please give ideas to improve it as needed

## So here is how I want a workflow to make a website quickly using the skill:

### Phase 1 - Intro:
1) I will create a new folder for the project (making the new website)
2) I will add an `/intro/` folder
3) I will add an `INSTRUCTIONS.md` file and any other files connected to it (images, videos...) inside `/intro/` that will explain what kind of website I want, for what company (and type of company) it will be for and what requiremets i may have
4) I will initiate a Claude Code session in the project's directory
5) I will run the skill
6) The skill will make Claude read all of `/intro/` 
7) The skill will make Claude ask me if I want to initiate `Phase 2`
8) I will say yes

### Phase 2 - Site Map:
1) The skill will make Claude ask me any questions needed to better understand the project using `AskUserQuestions`
2) The skill will make Claude think of a first `site map` idea
3) I will tell Claude what I think about it and probablye correct it at the same time
4) The skill will make Claude continue brainstorming, talking and using `AskUserQuestions` when needed (including to ask what special pages I want (admin section...)) until I tell Claude to start `Phase 3`

### Phase 3 - Claude Design:
- `Phase 3` will be me working with Claude Design, so Claude Code (and this skill) will do almost nothing here 
1) The skill will make Claude add a `/design-handoff-start/` folder
2) The skill will make Claude write `SITEMAP.md`, `BRANDING.md` and `INSTRUCTIONS.md` and any other files connected to them (images, videos...) inside `/design-handoff-start/` (YOUR ATTENTION: TELL ME IF WE WHOULD USE ANY OTHER FORMAT FOR THESE FILES) which will contain the sitemap and instructions for Claude Design, including what parts of the sitemap will have their own page while designing, explaining to it what it must do and, most importantly, explaining to it what is the desired output style for when me and Claude Design have finished working on the frontend
3) I will upload the whole `/design-handoff-start/` folder in Claude Design
4) When finished, I will tell Claude Design to create `/design-handoff-end/` (most probably make it into a .zip and following the output style the skill will contain (output style always same for all projects))
5) I will copy `/design-handoff-end/` inside the project's root directory
6) I will tell Claude Code to start `Phase 4`

### Phase 4 - Building:
- `Phase 4` will be the final phase where I will work with Claude Code to actually make the functioning website
1) The skill will make Claude read `/design-handoff-end/` 
2) The skill will make Claude use `AskUserQuestions` as much as needed
3) The skill will make Claude build the website, and then I will correct it, use the skills I want etc.
4) When I tell Claude that the skill is finished, it will stop running it and we will just continue building as normal

## The Skill
- This is a skill to actually create a website, as you already know
- You have to understand that the skill will provide the BACKEND for the website
- To do that, you will have to describe **very precisely** how `kapriol.ergotrade.bg` 's backend works (without mentioning it anywhere inside the skill of course)
- The idea is that by having already an **incredibly precise** backend creation instructions, it will make a website very quickly after `Phase 3` is finished (a.k.a. when a clear frontend will be provided)
- Of course, the skill will be made of many different files to precisely tell claude what to do, how to do it, what the backend will exactly look like etc.